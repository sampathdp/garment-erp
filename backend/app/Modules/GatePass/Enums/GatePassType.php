<?php

declare(strict_types=1);

namespace App\Modules\GatePass\Enums;

use App\Support\Enums\HasValues;

enum GatePassType: string
{
    use HasValues;

    case OutboundSupplier = 'outbound_supplier';
    case ReturnSupplier = 'return_supplier';
    case Delivery = 'delivery';
    case Other = 'other';
}
