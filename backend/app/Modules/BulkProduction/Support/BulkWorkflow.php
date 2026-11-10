<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Shared steps of the bulk Actions. Lock order: the ORDER row first, then the bulk job (the
 * same order CreateBulkJobIfEligible and the garment list actions use), so concurrent requests
 * serialise instead of deadlocking.
 *
 * Order stages driven by the bulk job:
 *   design_approved → bulk_assigned      (first assignment)
 *   bulk_assigned   → bulk_preparation   (first work: measurement, checklist, file, evidence)
 *   bulk_preparation → print_preparation (bulk completed: approved bulk / external completion,
 *                                         measurements, checklist and locked list all in place)
 */
final class BulkWorkflow
{
    /** Order stages in which bulk work may happen. */
    public const ACTIVE_ORDER_STAGES = [OrderStatus::BulkAssigned, OrderStatus::BulkPreparation];

    public function __construct(private readonly TransitionOrder $transition) {}

    /**
     * Locks the order and the job (in that order) and checks the optimistic version when given.
     *
     * @return array{0: BulkJob, 1: Order}
     */
    public function lock(BulkJob $job, ?int $expectedVersion = null): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Bulk changes must run inside a transaction.');
        }

        $order = Order::query()->lockForUpdate()->findOrFail($job->order_id);
        $job = BulkJob::query()->lockForUpdate()->findOrFail($job->id);
        if ($expectedVersion !== null && $job->version !== $expectedVersion) {
            throw ApiException::conflict('version_conflict', 'The bulk job was changed by someone else. Reload it and try again.');
        }

        return [$job, $order];
    }

    /** Bulk work only while the order is in a bulk stage (not on hold, not cancelled, not moved on). */
    public function ensureOrderInBulkStage(Order $order): void
    {
        if (! in_array($order->status, self::ACTIVE_ORDER_STAGES, true)) {
            throw ApiException::conflict('order_not_in_bulk_stage', "The job is {$order->status->label()}; bulk work is not possible now.");
        }
    }

    /** First work on an assigned job: job → in_progress, order → bulk_preparation. */
    public function start(BulkJob $job, Order $order, User $actor): void
    {
        if ($job->status === BulkJobStatus::Assigned) {
            $job->forceFill(['status' => BulkJobStatus::InProgress]);
        }
        if ($order->status === OrderStatus::BulkAssigned) {
            $this->transition->handle($order, OrderStatus::BulkPreparation, $actor, 'Bulk preparation started.');
        }
    }

    /** Saves the job with an incremented version (every change, so stale clients get a 409). */
    public function save(BulkJob $job): void
    {
        $job->forceFill(['version' => $job->version + 1])->save();
    }

    /** Bulk completed: the order leaves the bulk stage for print preparation. */
    public function finishOrderStage(Order $order, User $actor): void
    {
        if ($order->status === OrderStatus::BulkAssigned) {
            $this->transition->handle($order, OrderStatus::BulkPreparation, $actor, 'Bulk preparation started.');
        }
        $this->transition->handle($order, OrderStatus::PrintPreparation, $actor, 'Bulk completed.');
    }

    /** The job's list reference is still the order's current, confirmed (locked) list version. */
    public function listIsCurrentAndLocked(BulkJob $job): bool
    {
        $version = GarmentListVersion::query()->select(['id', 'garment_list_id', 'status'])->find($job->getAttribute('garment_list_version_id'));
        if ($version === null || $version->status !== GarmentListVersionStatus::Locked) {
            return false;
        }

        return (int) GarmentList::query()->whereKey($version->garment_list_id)->value('current_version_id') === $version->id;
    }

    public static function productTypeId(BulkJob $job): int
    {
        return (int) OrderItem::query()->where('order_id', $job->order_id)->orderBy('sort_order')->value('product_type_id');
    }
}
