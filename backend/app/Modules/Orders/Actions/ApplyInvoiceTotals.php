<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\AdvanceRule;
use App\Modules\Orders\Support\OrderNotifier;

/**
 * Once the confirmed list is priced, the issued invoice becomes the order's invoice total:
 * the required 30% advance is recalculated from it and the remaining balance follows.
 * If verified payments no longer cover the advance, the job is not production eligible and the
 * Page Owners (and, because the job is already released to operations, the Operation Admins) are told.
 *
 * Caller holds the order lock. The intake estimate on order_items stays as history; the invoice
 * lines are the priced snapshot.
 */
final class ApplyInvoiceTotals
{
    public function __construct(private readonly EvaluateOrderRelease $release, private readonly OrderNotifier $notifier, private readonly AuditLogger $audit) {}

    /** @param  array{subtotal_minor: int, discount_minor: int, tax_minor: int, delivery_fee_minor: int, total_minor: int}  $totals */
    public function handle(User $actor, Order $order, array $totals): void
    {
        $before = $order->only(['total_minor', 'advance_required_minor']);
        $order->forceFill([
            ...$totals,
            'advance_required_minor' => AdvanceRule::requiredMinor($totals['total_minor'], $order->advance_percent),
            'version' => $order->version + 1,
        ])->save();

        $this->release->handle($order, $actor, 'Invoice issued.');
        $this->audit->record('orders.invoice_totals_applied', $order, ['before' => $before, 'after' => $order->only(['total_minor', 'advance_required_minor'])], actor: $actor);

        if (! $order->meetsAdvanceRequirement()) {
            $this->notifier->advanceBelowMinimum($order, null, notifyOperations: $order->status->isReleased());
        }
    }
}
