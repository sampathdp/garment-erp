<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

use App\Support\Enums\HasValues;

enum DeliveryStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Failed = 'failed';
}
