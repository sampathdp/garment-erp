<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Support\Exceptions\ApiException;

/**
 * Backend-only invoice totals (business invariant 1). The client sends the agreed unit price,
 * delivery fee and discount; every total and the required advance are calculated here:
 *
 *   subtotal = gross quantity × unit price
 *   total    = subtotal − discount + tax (0 until Q9) + delivery fee
 *   advance  = ceil(total × advance_percent / 100)
 */
final class OrderPricing
{
    public static function apply(Order $order, OrderItem $item, int $quantity, int $unitPriceMinor, int $deliveryFeeMinor, int $discountMinor): void
    {
        $subtotal = $quantity * $unitPriceMinor;
        if ($discountMinor > $subtotal) {
            throw new ApiException(422, 'validation_failed', 'The discount cannot be larger than the subtotal.', ['discount_minor' => ['The discount cannot be larger than the subtotal.']]);
        }

        $item->forceFill([
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'options_unit_minor' => 0,
            'line_total_minor' => $subtotal,
        ]);

        $total = $subtotal - $discountMinor + 0 + $deliveryFeeMinor;
        $order->forceFill([
            'total_quantity' => $quantity,
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discountMinor,
            'tax_minor' => 0,
            'delivery_fee_minor' => $deliveryFeeMinor,
            'total_minor' => $total,
            'advance_required_minor' => AdvanceRule::requiredMinor($total, $order->advance_percent),
        ]);
    }
}
