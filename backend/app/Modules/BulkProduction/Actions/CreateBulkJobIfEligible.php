<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Invoices\Enums\InvoiceStatus;
use App\Modules\Invoices\Enums\InvoiceType;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Organizations\Support\WorkflowSettings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A bulk job becomes eligible only when ALL prerequisites hold:
 *   1. the design is approved and its final PDF/CDR or Drive link was submitted (task completed);
 *   2. the name/number list is confirmed and locked;
 *   3. the invoice for that locked list is issued;
 *   4. financial eligibility: verified payments ≥ 30% of the invoice total;
 *   5. the order is at "design approved" (active, released).
 *
 * Called after each event that can complete the set (final design, list confirmation, payment
 * verification). Creates the job at most once (order row lock + UNIQUE order_id) and notifies the
 * configured Bulk Designer and the Operation Admins.
 */
final class CreateBulkJobIfEligible
{
    public function __construct(
        private readonly WorkflowSettings $settings,
        private readonly StaffDirectory $staff,
        private readonly Notify $notify,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<array{key: string, label: string, met: bool}> */
    public function checklist(Order $order): array
    {
        $parts = $this->parts($order);

        return [
            ['key' => 'final_design', 'label' => 'Final design files or link submitted', 'met' => $parts['task'] !== null],
            ['key' => 'list_locked', 'label' => 'Name/number list confirmed and locked', 'met' => $parts['list_version'] !== null],
            ['key' => 'invoice', 'label' => 'Invoice issued for the confirmed list', 'met' => $parts['invoice'] !== null],
            ['key' => 'advance', 'label' => 'Verified advance covers 30% of the invoice', 'met' => $parts['invoice'] !== null && $order->meetsAdvanceRequirement()],
            ['key' => 'stage', 'label' => 'Order at design approved', 'met' => $order->status === OrderStatus::DesignApproved],
        ];
    }

    /** Caller holds the order lock (or this runs in its own transaction). */
    public function handle(Order $order, ?User $actor): ?BulkJob
    {
        return DB::transaction(function () use ($order, $actor): ?BulkJob {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if (BulkJob::query()->where('order_id', $order->id)->exists()) {
                return null; // never twice
            }

            $parts = $this->parts($order);
            if ($parts['task'] === null || $parts['list_version'] === null || $parts['invoice'] === null
                || ! $order->meetsAdvanceRequirement() || $order->status !== OrderStatus::DesignApproved) {
                return null;
            }

            $designer = $this->settings->autoAssignBulkDesigner()
                ? $this->staff->freeBulkDesignerFor($order, $this->settings->defaultBulkDesignerId())
                : $this->staff->eligible($this->settings->defaultBulkDesignerId(), Permissions::BULK_MEASURE);

            if ($designer === null && $this->settings->defaultBulkDesignerId() !== null) {
                $designer = $this->staff->eligible($this->settings->defaultBulkDesignerId(), Permissions::BULK_MEASURE);
            }

            $job = new BulkJob([
                'order_id' => $order->id, 'branch_id' => $order->branch_id, 'design_task_id' => $parts['task']->id,
                'garment_list_version_id' => $parts['list_version']->id, 'invoice_id' => $parts['invoice']->id, 'bulk_designer_id' => $designer?->id,
            ]);
            $job->forceFill(['status' => BulkJobStatus::PendingAssignment]);
            try {
                DB::transaction(fn () => $job->save()); // savepoint: a concurrent duplicate fails alone
            } catch (UniqueConstraintViolationException) {
                return null;
            }

            $this->audit->record('bulk.job_created', $job, ['after' => ['order_id' => $order->id, 'bulk_designer_id' => $designer?->id]], actor: $actor);
            $this->notify->send(
                fn (Recipients $r) => $r->withPermission(Permissions::BULK_ASSIGN, $order->branch_id, $order->page_id)->merge($designer ? [$designer] : []),
                new WorkflowNotification('bulk.job_ready', 'Bulk job ready', "Job {$order->order_no} is ready for bulk: final design, confirmed list, invoice and advance are complete.", "/bulk-jobs/view/?id={$job->id}", ['order_id' => $order->id, 'bulk_job_id' => $job->id]),
            );

            return $job;
        });
    }

    /** @return array{task: DesignTask|null, list_version: GarmentListVersion|null, invoice: Invoice|null} */
    private function parts(Order $order): array
    {
        $task = DesignTask::query()->where('order_id', $order->id)->where('status', DesignTaskStatus::Completed->value)->whereNotNull('final_version_id')->first();
        if ($task !== null && ! DesignVersion::query()->whereKey($task->getAttribute('final_version_id'))->where('is_final', true)->exists()) {
            $task = null;
        }

        $listVersion = null;
        $itemId = OrderItem::query()->where('order_id', $order->id)->orderBy('sort_order')->value('id');
        $list = $itemId === null ? null : GarmentList::query()->where('order_item_id', $itemId)->first();
        if ($list !== null) {
            $listVersion = GarmentListVersion::query()->whereKey($list->current_version_id)->where('status', GarmentListVersionStatus::Locked->value)->first();
        }

        $invoice = $listVersion === null ? null : Invoice::query()->where('order_id', $order->id)->where('type', InvoiceType::Final->value)
            ->where('status', InvoiceStatus::Issued->value)->where('garment_list_version_id', $listVersion->id)->first();

        return ['task' => $task, 'list_version' => $listVersion, 'invoice' => $invoice];
    }
}
