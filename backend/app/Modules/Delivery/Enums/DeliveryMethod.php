<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

use App\Support\Enums\HasValues;

enum DeliveryMethod: string
{
    use HasValues;

    case Pickup = 'pickup';
    case Courier = 'courier';
    case OwnVehicle = 'own_vehicle';

    public function label(): string
    {
        return match ($this) {
            self::Pickup => 'Customer pickup',
            self::Courier => 'Courier',
            self::OwnVehicle => 'Own vehicle',
        };
    }
}
