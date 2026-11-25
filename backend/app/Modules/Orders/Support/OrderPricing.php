<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Exceptions\ApiException;

/**
 * Intake figures (business invariant 1, client decision 2026-11-25). The price depends on the
 * printed quantity, so nothing is priced at intake: the order form carries the gross quantity,
 * delivery fee, discount and a fixed advance amount agreed with the customer. The totals stay 0
 * ("price pending") until the invoice is issued at Printing Completed (ApplyInvoiceTotals).
 *
 *   advance_required = the staff-entered advance, at least max(1, page minimum advance)
 */
final class OrderPricing
{
    public static function apply(Order $order, OrderItem $item, int $quantity, int $deliveryFeeMinor, int $discountMinor, int $advanceMinor, int $minimumAdvanceMinor): void
    {
        $minimum = max(1, $minimumAdvanceMinor);
        if ($advanceMinor < $minimum) {
            $message = $minimum > 1 ? 'The advance must be at least the page minimum.' : 'Enter the advance amount.';
            throw new ApiException(422, 'validation_failed', $message, ['advance_amount_minor' => [$message]]);
        }

        $item->forceFill([
            'quantity' => $quantity,
            'unit_price_minor' => 0,
            'options_unit_minor' => 0,
            'line_total_minor' => 0,
        ]);

        $order->forceFill([
            'total_quantity' => $quantity,
            'subtotal_minor' => 0,
            'discount_minor' => $discountMinor,
            'tax_minor' => 0,
            'delivery_fee_minor' => $deliveryFeeMinor,
            'total_minor' => 0,
            'advance_required_minor' => $advanceMinor,
        ]);
    }
}
