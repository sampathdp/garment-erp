<?php

declare(strict_types=1);

namespace App\Modules\Garments\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\BulkProduction\Actions\SyncBulkWithGarmentList;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListItemValue;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentListReview;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Actions\IssueOrderInvoice;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Notifications\Support\Notify;
use App\Modules\Notifications\Support\Recipients;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Actions\ApplyInvoiceTotals;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Queries\PriceQuote;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Confirmation and administrative correction of the name/number list.
 *
 * confirm(): the Page Owner confirms the reviewed list. Errors block; warnings must be
 * acknowledged; the list must be fully priced. Then, in ONE transaction: the version is locked
 * (who/when, total), a previous confirmed version is superseded, the invoice is issued from the
 * page's price list (snapshot) and becomes the order's invoice total, and the bulk job is created
 * if everything else is ready.
 *
 * correct(): exceptional post-confirmation change (garment_lists.unlock, reason required): a new
 * draft version is opened as a copy; the confirmed version and its invoice stay valid until the
 * corrected list is confirmed again (which voids and replaces the invoice).
 */
final class ConfirmGarmentList
{
    public function __construct(
        private readonly GarmentListReview $review,
        private readonly PriceQuote $pricing,
        private readonly IssueOrderInvoice $invoices,
        private readonly ApplyInvoiceTotals $totals,
        private readonly CreateBulkJobIfEligible $bulk,
        private readonly SyncBulkWithGarmentList $bulkSync,
        private readonly Notify $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function confirm(User $actor, GarmentList $list, int $expectedVersion, bool $acknowledgeWarnings): Invoice
    {
        return DB::transaction(function () use ($actor, $list, $expectedVersion, $acknowledgeWarnings): Invoice {
            $list = GarmentList::query()->lockForUpdate()->findOrFail($list->id);
            if ($list->version !== $expectedVersion) {
                throw ApiException::conflict('version_conflict', 'The list was changed by someone else. Reload it and try again.');
            }
            $version = GarmentListVersion::query()->lockForUpdate()->findOrFail($list->current_version_id);
            if (! $version->isEditable()) {
                throw ApiException::conflict('list_locked', 'The garment list is already confirmed.');
            }
            $item = OrderItem::query()->findOrFail($list->order_item_id);
            $order = Order::query()->lockForUpdate()->findOrFail($item->order_id);
            if (! $order->status->isReleased() || $order->status->isTerminal()) {
                throw ApiException::conflict('invalid_state', 'The list can be confirmed after payment approval, while the job is active.');
            }

            $schema = GarmentSchema::forProductType($item->product_type_id);
            $summary = $this->review->review($version, $schema, $item->quantity);
            if (! $summary['can_confirm']) {
                throw new ApiException(422, 'list_invalid', 'Fix the errors in the list before confirming.', [
                    'list' => array_map(fn (array $e) => ($e['line_no'] !== null ? "Row {$e['line_no']}: " : '').$e['message'], $summary['errors']),
                ]);
            }
            if ($summary['warnings'] !== [] && ! $acknowledgeWarnings) {
                throw new ApiException(422, 'list_warnings', 'Review and acknowledge the warnings before confirming.', [
                    'acknowledge_warnings' => array_map(fn (array $w) => $w['message'], $summary['warnings']),
                ]);
            }

            $pricingDate = BusinessDate::today();
            $quote = $this->pricing->quote($order->page_id, $order->branch_id, $item->product_type_id, $order->material_id, $pricingDate, $summary['price_groups']);

            GarmentListVersion::query()->where('garment_list_id', $list->id)->where('status', GarmentListVersionStatus::Locked->value)
                ->update(['status' => GarmentListVersionStatus::Superseded->value, 'updated_at' => now()]);
            $version->forceFill(['status' => GarmentListVersionStatus::Locked, 'locked_by' => $actor->id, 'locked_at' => now(), 'total_quantity' => $summary['total_quantity']])->save();
            $list->forceFill(['version' => $list->version + 1])->save();

            $invoice = $this->invoices->handle($actor, $order, $item->product_type_id, $quote['price_list']->id, $version->id, $pricingDate, $quote['lines']);
            $this->totals->handle($actor, $order, $invoice->only(['subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_fee_minor', 'total_minor']));

            $this->audit->record('garment_lists.confirmed', $version, ['after' => [
                'version_no' => $version->version_no, 'total_quantity' => $summary['total_quantity'], 'warnings_acknowledged' => count($summary['warnings']), 'invoice_id' => $invoice->id,
            ]], actor: $actor, branchId: $order->branch_id);

            $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id)->merge($r->withPermission('payments.verify', $order->branch_id, $order->page_id)),
                new WorkflowNotification('invoice.issued', 'Invoice issued', "Invoice {$invoice->invoice_no} was issued for job {$order->order_no} after the name/number list was confirmed.", "/orders/view/?id={$order->id}", ['order_id' => $order->id, 'invoice_id' => $invoice->id]), $actor);

            $this->bulkSync->listConfirmed($actor, $order->id, $version->id);
            $this->bulk->handle($order, $actor);

            return $invoice;
        });
    }

    public function correct(User $actor, GarmentList $list, string $reason): GarmentList
    {
        return DB::transaction(function () use ($actor, $list, $reason): GarmentList {
            $list = GarmentList::query()->lockForUpdate()->findOrFail($list->id);
            $locked = GarmentListVersion::query()->findOrFail($list->current_version_id);
            if ($locked->status !== GarmentListVersionStatus::Locked) {
                throw ApiException::conflict('invalid_state', 'Only a confirmed list can be corrected.');
            }
            $orderId = (int) OrderItem::query()->whereKey($list->order_item_id)->value('order_id');
            // A completed bulk locks the list; a bulk in review or approved is invalidated.
            $this->bulkSync->listReopened($actor, $orderId, $reason);

            $draft = new GarmentListVersion(['garment_list_id' => $list->id, 'change_reason' => $reason]);
            $draft->forceFill(['version_no' => $locked->version_no + 1, 'status' => GarmentListVersionStatus::Draft, 'created_by' => $actor->id])->save();

            foreach ($locked->items()->with('values')->orderBy('line_no')->get() as $item) {
                $copy = GarmentListItem::query()->create([...$item->only(['line_no', 'player_name', 'number', 'size_id', 'quantity', 'notes']), 'garment_list_version_id' => $draft->id]);
                foreach ($item->values as $value) {
                    GarmentListItemValue::query()->create(['garment_list_item_id' => $copy->id, 'field_key' => $value->field_key, 'value' => $value->value]);
                }
            }

            $list->forceFill(['current_version_id' => $draft->id, 'version' => $list->version + 1])->save();
            $order = Order::query()->select(['id', 'branch_id', 'page_id', 'order_no'])->findOrFail($orderId);
            $this->audit->record('garment_lists.correction_opened', $list, ['after' => ['from_version' => $locked->version_no, 'to_version' => $draft->version_no, 'reason' => $reason]], actor: $actor, branchId: $order->branch_id);

            $this->notify->send(fn (Recipients $r) => $r->pageOwners($order->page_id),
                new WorkflowNotification('garment_list.correction_opened', 'Name/number list reopened for correction', "The confirmed list of job {$order->order_no} was reopened: {$reason}", "/orders/garment-list/?id={$order->id}", ['order_id' => $order->id]), $actor);

            return $list;
        });
    }
}
