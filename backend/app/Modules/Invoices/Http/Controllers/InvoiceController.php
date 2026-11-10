<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Http\Controllers;

use App\Modules\Invoices\Enums\InvoiceStatus;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Read-only invoice snapshots with advance tracking and remaining balance. */
final class InvoiceController
{
    public function forOrder(Order $order): JsonResponse
    {
        Gate::authorize('view', $order);
        abort_unless(request()->user()?->can('invoices.view'), 403);
        $invoices = Invoice::query()->where('order_id', $order->id)->orderByDesc('id')->get();

        return ApiResponse::data(['items' => $invoices->map(fn (Invoice $i) => $this->present($i, $order, false))->all()]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        Gate::authorize('view', $invoice);
        $invoice->load(['items' => fn ($q) => $q->orderBy('sort_order'), 'issuer:id,name', 'voider:id,name', 'page:id,code,name,invoice_header_name']);
        $order = Order::query()->with('customer:id,name,customer_code')->findOrFail($invoice->order_id);

        return ApiResponse::data($this->present($invoice, $order, true));
    }

    /** @return array<string, mixed> */
    private function present(Invoice $invoice, Order $order, bool $detail): array
    {
        $current = $invoice->status === InvoiceStatus::Issued;

        return [
            'id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no,
            'type' => $invoice->type->value,
            'status' => $invoice->status->value,
            'currency' => $invoice->currency,
            'subtotal_minor' => $invoice->subtotal_minor,
            'discount_minor' => $invoice->discount_minor,
            'tax_percent' => $invoice->getAttribute('tax_percent'),
            'tax_minor' => $invoice->tax_minor,
            'delivery_fee_minor' => $invoice->delivery_fee_minor,
            'total_minor' => $invoice->total_minor,
            'pricing_date' => $invoice->pricing_date?->format('Y-m-d'),
            'issued_at' => $invoice->issued_at?->toIso8601ZuluString(),
            'void_reason' => $invoice->void_reason,
            // Advance tracking against this invoice (only meaningful for the current, issued invoice).
            'payments' => $current ? [
                'advance_percent' => $order->advance_percent,
                'advance_required_minor' => $order->advance_required_minor,
                'verified_paid_minor' => $order->verified_paid_minor,
                'advance_shortfall_minor' => $order->advanceShortfallMinor(),
                'balance_minor' => max(0, $invoice->total_minor - $order->verified_paid_minor),
            ] : null,
            'order' => $detail ? ['id' => $order->id, 'order_no' => $order->order_no, 'customer' => $order->customer->name] : ['id' => $order->id],
            'page' => $detail && $invoice->relationLoaded('page') ? ['code' => $invoice->page->code, 'name' => $invoice->page->name, 'header' => $invoice->page->invoice_header_name] : null,
            'issued_by' => $detail ? $invoice->issuer?->name : null,
            'lines' => $detail ? $invoice->items->map(fn (InvoiceItem $l): array => [
                'description' => $l->description, 'quantity' => $l->quantity, 'unit_price_minor' => $l->unit_price_minor, 'line_total_minor' => $l->line_total_minor,
            ])->all() : null,
        ];
    }
}
