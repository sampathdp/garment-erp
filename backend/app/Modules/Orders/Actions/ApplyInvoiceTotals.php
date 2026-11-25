<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;

/**
 * Once printing is completed and priced, the issued invoice becomes the order's total and the
 * remaining balance follows. The advance is the fixed amount agreed at intake and does not change.
 *
 * Caller holds the order lock. The intake estimate on order_items stays as history; the invoice
 * lines are the priced snapshot.
 */
final class ApplyInvoiceTotals
{
    public function __construct(private readonly EvaluateOrderRelease $release, private readonly AuditLogger $audit) {}

    /** @param  array{subtotal_minor: int, discount_minor: int, tax_minor: int, delivery_fee_minor: int, total_minor: int}  $totals */
    public function handle(User $actor, Order $order, array $totals): void
    {
        $before = $order->only(['total_minor', 'advance_required_minor']);
        $order->forceFill([...$totals, 'version' => $order->version + 1])->save();

        $this->release->handle($order, $actor, 'Invoice issued.');
        $this->audit->record('orders.invoice_totals_applied', $order, ['before' => $before, 'after' => $order->only(['total_minor', 'advance_required_minor'])], actor: $actor);
    }
}
