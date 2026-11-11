<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Actions\OpenDelivery;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Sewing\Enums\SewingJobStatus;
use App\Modules\Sewing\Models\SewingJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The minimal sewing step (Phase 08; user decision 2026-11-11):
 *
 *  - open():     called by CompleteCuttingJob in the same transaction; one sewing job per
 *                cutting job (idempotent). Received quantity = the pieces cut.
 *  - start():    pending → in_progress.
 *  - complete(): records the sewn quantity, which must equal the received quantity (a shortfall
 *                is a production issue, not a completion). Moves the order sewing →
 *                ready_for_delivery and opens the delivery record.
 *
 * Lock order: the ORDER row, then the sewing job.
 */
final class RunSewing
{
    public function __construct(private readonly TransitionOrder $transition, private readonly OpenDelivery $delivery, private readonly AuditLogger $audit) {}

    public function open(CuttingJob $cutting, Order $order, User $actor): SewingJob
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A sewing job is opened inside the cutting completion transaction.');
        }
        $existing = SewingJob::query()->where('cutting_job_id', $cutting->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $job = new SewingJob(['order_id' => $order->id, 'order_item_id' => $cutting->getAttribute('order_item_id'), 'cutting_job_id' => $cutting->id, 'received_quantity' => $cutting->cut_quantity]);
        $job->forceFill(['branch_id' => $order->branch_id, 'status' => SewingJobStatus::Pending, 'sewn_quantity' => 0, 'version' => 1])->save();
        $this->audit->record('sewing.job_opened', $job, ['after' => ['cutting_job_id' => $cutting->id, 'received_quantity' => $cutting->cut_quantity]], actor: $actor, branchId: $order->branch_id);

        return $job;
    }

    public function start(User $actor, SewingJob $job, int $expectedVersion): SewingJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion): SewingJob {
            [$job, $order] = $this->lock($job, $expectedVersion);
            if ($job->status !== SewingJobStatus::Pending) {
                throw ApiException::conflict('invalid_state', 'Sewing has already started.');
            }
            $job->forceFill(['status' => SewingJobStatus::InProgress, 'started_by' => $actor->id, 'started_at' => now(), 'version' => $job->version + 1])->save();
            $this->audit->record('sewing.started', $job, ['after' => ['status' => SewingJobStatus::InProgress->value]], actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }

    public function complete(User $actor, SewingJob $job, int $expectedVersion, int $sewn, ?string $notes): SewingJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $sewn, $notes): SewingJob {
            [$job, $order] = $this->lock($job, $expectedVersion);
            if ($job->status !== SewingJobStatus::InProgress) {
                throw ApiException::conflict('invalid_state', $job->status === SewingJobStatus::Completed ? 'Sewing is already completed.' : 'Start sewing before completing it.');
            }
            if ($sewn !== $job->received_quantity) {
                throw new ApiException(422, 'validation_failed', "The sewn quantity must equal the received quantity ({$job->received_quantity}). Report a shortfall as a production issue.",
                    ['sewn_quantity' => ["The sewn quantity must equal the received quantity ({$job->received_quantity})."]]);
            }

            $job->forceFill(['status' => SewingJobStatus::Completed, 'sewn_quantity' => $sewn, 'completed_by' => $actor->id, 'completed_at' => now(), 'notes' => $notes, 'version' => $job->version + 1])->save();
            $this->audit->record('sewing.completed', $job, ['after' => ['sewn_quantity' => $sewn, 'notes' => $notes]], actor: $actor, branchId: $order->branch_id);
            $this->transition->handle($order, OrderStatus::ReadyForDelivery, $actor, 'Sewing completed.');
            $this->delivery->handle($order, $actor);

            return $job;
        });
    }

    /** @return array{0: SewingJob, 1: Order} */
    private function lock(SewingJob $job, int $expectedVersion): array
    {
        $order = Order::query()->lockForUpdate()->findOrFail($job->order_id);
        $job = SewingJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($job->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The sewing job was changed by someone else. Reload it and try again.');
        }
        if ($order->status !== OrderStatus::Sewing) {
            throw ApiException::conflict('order_not_in_sewing_stage', "The job is {$order->status->label()}; sewing work is not possible now.");
        }

        return [$job, $order];
    }
}
