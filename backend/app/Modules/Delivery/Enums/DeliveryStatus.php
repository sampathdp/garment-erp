<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

use App\Support\Enums\HasValues;

/**
 * Delivery statuses (Phase 08).
 *
 *   ready → dispatched → delivered        (courier / own vehicle)
 *   ready → delivered                     (customer pickup)
 *
 * The order follows: ready_for_delivery → out_for_delivery → delivered → completed.
 */
enum DeliveryStatus: string
{
    use HasValues;

    case Ready = 'ready';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready for Delivery',
            self::Dispatched => 'Out for Delivery',
            self::Delivered => 'Delivered',
        };
    }
}
