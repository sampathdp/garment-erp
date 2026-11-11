<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Support;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobEvent;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Shared steps of the cutting Actions. Lock order: the ORDER row first, then the cutting job
 * (the same order the printing and bulk actions use), so concurrent requests serialise.
 *
 * The order stays in `cutting` for the whole cutting job; completion moves it to `sewing`.
 */
final class CuttingWorkflow
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Locks the order and the job (in that order) and checks the optimistic version when given.
     *
     * @return array{0: CuttingJob, 1: Order}
     */
    public function lock(CuttingJob $job, ?int $expectedVersion = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cutting changes must run inside a transaction.');
        }

        $order = Order::query()->lockForUpdate()->findOrFail($job->order_id);
        $job = CuttingJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($expectedVersion !== null && $job->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The cutting job was changed by someone else. Reload it and try again.');
        }

        return [$job, $order];
    }

    /** Cutting work is possible only while the order is in the cutting stage (not on hold or cancelled). */
    public function ensureOrderStage(Order $order): void
    {
        if ($order->status !== OrderStatus::Cutting) {
            throw ApiException::conflict('order_not_in_cutting_stage', "The job is {$order->status->label()}; cutting work is not possible now.");
        }
    }

    /** Work needs an assigned cutter and an open job. */
    public function ensureAcceptsWork(CuttingJob $job): void
    {
        if (! $job->status->acceptsWork()) {
            throw ApiException::conflict('invalid_state', $job->status === CuttingJobStatus::Completed
                ? 'This cutting job is completed.' : 'Assign a cutter before recording cutting work.');
        }
    }

    /**
     * The ONLY place a cutting job's status changes: validates the transition, writes the event
     * row and the audit entry.
     *
     * @param  array<string, mixed>  $details  extra audit data
     */
    public function transition(CuttingJob $job, Order $order, CuttingJobStatus $to, User $actor, string $auditAction, ?string $note = null, array $details = []): void
    {
        $from = $job->status;
        if (! $from->canTransitionTo($to)) {
            throw ApiException::conflict('invalid_transition', "The cutting job cannot move from {$from->label()} to {$to->label()}.");
        }

        $job->forceFill(['status' => $to]);
        CuttingJobEvent::query()->create(['cutting_job_id' => $job->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note === null ? null : mb_substr($note, 0, 1000), 'actor_id' => $actor->id]);
        $this->audit->record($auditAction, $job, ['before' => ['status' => $from->value], 'after' => ['status' => $to->value, 'note' => $note, ...$details]], actor: $actor, branchId: $order->branch_id);
    }

    /** The first recorded work moves an assigned job to in progress. */
    public function start(CuttingJob $job, Order $order, User $actor): void
    {
        if ($job->status === CuttingJobStatus::Assigned) {
            $job->forceFill(['started_at' => now()]);
            $this->transition($job, $order, CuttingJobStatus::InProgress, $actor, 'cutting.started');
        }
    }

    /** Saves the job with an incremented version (every change, so stale clients get a 409). */
    public function save(CuttingJob $job): void
    {
        $job->forceFill(['version' => $job->version + 1])->save();
    }

    public static function productTypeId(CuttingJob $job): int
    {
        return (int) DB::table('order_items')->where('id', $job->getAttribute('order_item_id'))->value('product_type_id');
    }
}
