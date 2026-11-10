<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Enums\InvoiceStatus;
use App\Modules\Invoices\Enums\InvoiceType;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Actions\NextSequenceValue;
use App\Modules\Organizations\Models\Page;
use App\Support\Time\BusinessDate;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Issues the order's (final) invoice from a price quote, as an immutable snapshot: lines, unit
 * prices, the price list and the pricing date are copied, so later price-list changes never alter
 * it. A previous issued invoice of the order (an earlier list confirmation) is voided with a reason.
 *
 *   subtotal = Σ lines;  discount = the order's authorised discount (≤ subtotal);
 *   tax = round(taxable × tax% / 100) only when a tax rate is configured;  total = subtotal − discount + tax + delivery
 *
 * Numbers: `{invoice prefix}-{YYYY}-{NNNNNN}`, sequential per page per year (NextSequenceValue).
 * Runs inside the caller's transaction with the order locked.
 */
final class IssueOrderInvoice
{
    public function __construct(private readonly NextSequenceValue $sequence, private readonly AuditLogger $audit) {}

    /** @param  list<array{description: string, quantity: int, unit_price_minor: int, line_total_minor: int, price_list_item_id: int}>  $lines */
    public function handle(User $actor, Order $order, int $productTypeId, int $priceListId, int $garmentListVersionId, CarbonImmutable $pricingDate, array $lines): Invoice
    {
        if ($lines === []) {
            throw new LogicException('An invoice needs at least one line.');
        }

        $previous = Invoice::query()->where('order_id', $order->id)->where('type', InvoiceType::Final->value)
            ->where('status', InvoiceStatus::Issued->value)->lockForUpdate()->get();
        foreach ($previous as $old) {
            $old->forceFill(['status' => InvoiceStatus::Void, 'void_reason' => 'Replaced after the name/number list was corrected.', 'voided_at' => now(), 'voided_by' => $actor->id])->save();
            $this->audit->record('invoices.voided', $old, ['after' => ['reason' => $old->void_reason]], actor: $actor);
        }

        $subtotal = array_sum(array_column($lines, 'line_total_minor'));
        $discount = min($order->discount_minor, $subtotal);
        $taxPercent = max(0, (int) config('invoices.tax_percent', 0));
        $tax = $taxPercent > 0 ? intdiv(($subtotal - $discount) * $taxPercent + 50, 100) : 0;
        $total = $subtotal - $discount + $tax + $order->delivery_fee_minor;

        $page = Page::query()->select(['id', 'invoice_prefix'])->findOrFail($order->page_id);
        $year = (int) BusinessDate::today()->format('Y');
        $number = sprintf('%s-%d-%06d', $page->invoice_prefix, $year, $this->sequence->handle('invoice', $page->id, $year));

        $invoice = new Invoice(['order_id' => $order->id, 'type' => InvoiceType::Final, 'related_invoice_id' => $previous->last()?->id, 'currency' => $order->currency]);
        $invoice->forceFill([
            'invoice_no' => $number, 'page_id' => $order->page_id, 'branch_id' => $order->branch_id, 'status' => InvoiceStatus::Issued,
            'price_list_id' => $priceListId, 'garment_list_version_id' => $garmentListVersionId, 'pricing_date' => $pricingDate->toDateString(),
            'tax_percent' => $taxPercent, 'subtotal_minor' => $subtotal, 'discount_minor' => $discount, 'tax_minor' => $tax,
            'delivery_fee_minor' => $order->delivery_fee_minor, 'total_minor' => $total, 'issued_at' => now(), 'issued_by' => $actor->id,
        ])->save();

        foreach ($lines as $i => $line) {
            InvoiceItem::query()->create([...$line, 'invoice_id' => $invoice->id, 'sort_order' => $i, 'product_type_id' => $productTypeId]);
        }

        $this->audit->record('invoices.issued', $invoice, ['after' => ['invoice_no' => $number, 'total_minor' => $total, 'price_list_id' => $priceListId, 'replaces' => $previous->pluck('id')->all()]], actor: $actor);

        return $invoice;
    }
}
