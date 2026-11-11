<?php

declare(strict_types=1);

namespace App\Modules\Printing\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobEvent;
use App\Modules\Printing\Support\PrintingNotifications;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Opens the printing job of a COMPLETED bulk job — called by CompleteBulkJob in the same
 * transaction (order + bulk job already locked), so a printing job exists exactly when the bulk
 * is completed. Idempotent: at most one printing job per bulk job (UNIQUE bulk_job_id).
 *
 * An incomplete bulk job is never exposed as printing-ready: the call is refused (409).
 */
final class OpenPrintJob
{
    public function __construct(private readonly PrintingNotifications $notify, private readonly AuditLogger $audit) {}

    public function handle(BulkJob $bulk, Order $order, User $actor): PrintJob
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A printing job is opened inside the bulk completion transaction.');
        }
        if ($bulk->status !== BulkJobStatus::Completed || $order->status !== OrderStatus::PrintPreparation || $bulk->mode === null) {
            throw ApiException::conflict('bulk_not_completed', 'A printing job is available only after the bulk job is completed.');
        }

        $existing = PrintJob::query()->where('bulk_job_id', $bulk->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $designVersionId = DesignTask::query()->whereKey($bulk->getAttribute('design_task_id'))->value('approved_version_id');
        $itemId = OrderItem::query()->where('order_id', $order->id)->orderBy('sort_order')->value('id');
        if ($designVersionId === null || $itemId === null || $bulk->getAttribute('garment_list_version_id') === null) {
            throw ApiException::conflict('bulk_not_completed', 'The approved design or the confirmed list is missing; the job cannot be printed.');
        }

        $job = new PrintJob([
            'order_id' => $order->id,
            'order_item_id' => (int) $itemId,
            'bulk_job_id' => $bulk->id,
            'design_version_id' => (int) $designVersionId,
            'garment_list_version_id' => (int) $bulk->getAttribute('garment_list_version_id'),
            // Internal: the final bulk version (files + WeTransfer link). External: none.
            'bulk_version_id' => BulkVersion::query()->where('bulk_job_id', $bulk->id)->where('is_final', true)->max('id'),
            'mode' => $bulk->mode,
        ]);
        $job->forceFill(['branch_id' => $order->branch_id, 'status' => PrintJobStatus::ReadyForPrinting, 'prepared_by' => $actor->id, 'version' => 1])->save();

        PrintJobEvent::query()->create(['print_job_id' => $job->id, 'from_status' => null, 'to_status' => PrintJobStatus::ReadyForPrinting, 'note' => 'Bulk completed.', 'actor_id' => $actor->id]);
        $this->audit->record('printing.job_opened', $job, ['after' => ['status' => PrintJobStatus::ReadyForPrinting->value, 'bulk_job_id' => $bulk->id, 'mode' => $bulk->mode->value]], actor: $actor, branchId: $order->branch_id);
        $this->notify->ready($job, $order, $actor);

        return $job;
    }
}
