<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobEvent;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Shared steps of the printing Actions. Lock order: the ORDER row first, then the print job
 * (the same order the bulk and list actions use), so concurrent requests serialise.
 *
 * Printing status → order stage:
 *   ready_for_printing / sent_for_printing / sample_rejected → print_preparation
 *   sample_pending_approval                                → print_sample_review
 *   sample_approved / physical_printing / printing_completed → printing
 */
final class PrintingWorkflow
{
    public function __construct(private readonly TransitionOrder $transition, private readonly AuditLogger $audit) {}

    /**
     * Locks the order and the job (in that order) and checks the optimistic version when given.
     *
     * @return array{0: PrintJob, 1: Order}
     */
    public function lock(PrintJob $job, ?int $expectedVersion = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Printing changes must run inside a transaction.');
        }

        $order = Order::query()->lockForUpdate()->findOrFail($job->order_id);
        $job = PrintJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($expectedVersion !== null && $job->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The printing job was changed by someone else. Reload it and try again.');
        }

        return [$job, $order];
    }

    /** The order must be in the stage that matches the job status (not on hold, cancelled or elsewhere). */
    public function ensureOrderStage(PrintJob $job, Order $order): void
    {
        if ($order->status !== self::orderStageFor($job->status)) {
            throw ApiException::conflict('order_not_in_printing_stage', "The job is {$order->status->label()}; printing work is not possible now.");
        }
    }

    /**
     * The ONLY place a print job's status changes: validates the transition, writes the event row
     * and the audit entry, and moves the order to the matching stage through TransitionOrder.
     *
     * @param  array<string, mixed>  $details  extra audit data
     */
    public function transition(PrintJob $job, Order $order, PrintJobStatus $to, User $actor, string $auditAction, ?string $note = null, array $details = []): void
    {
        $from = $job->status;
        if (! $from->canTransitionTo($to)) {
            throw ApiException::conflict('invalid_transition', "The printing job cannot move from {$from->label()} to {$to->label()}.");
        }

        $job->forceFill(['status' => $to]);
        PrintJobEvent::query()->create(['print_job_id' => $job->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note === null ? null : mb_substr($note, 0, 1000), 'actor_id' => $actor->id]);

        $stage = self::orderStageFor($to);
        if ($order->status !== $stage) {
            $this->transition->handle($order, $stage, $actor, "Printing: {$to->label()}.");
        }

        $this->audit->record($auditAction, $job, ['before' => ['status' => $from->value], 'after' => ['status' => $to->value, 'note' => $note, ...$details]], actor: $actor, branchId: $order->branch_id);
    }

    /** Saves the job with an incremented version (every change, so stale clients get a 409). */
    public function save(PrintJob $job): void
    {
        $job->forceFill(['version' => $job->version + 1])->save();
    }

    public static function orderStageFor(PrintJobStatus $status): OrderStatus
    {
        return match ($status) {
            PrintJobStatus::SamplePendingApproval => OrderStatus::PrintSampleReview,
            PrintJobStatus::SampleApproved, PrintJobStatus::PhysicalPrinting, PrintJobStatus::PrintingCompleted => OrderStatus::Printing,
            default => OrderStatus::PrintPreparation,
        };
    }
}
