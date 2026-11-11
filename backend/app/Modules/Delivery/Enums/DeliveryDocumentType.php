<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

use App\Support\Enums\HasValues;

/**
 * Delivery documents (Phase 08). Required before job completion, by delivery method
 * (user decision 2026-11-11): courier → waybill; own vehicle → delivery sheet; customer
 * pickup → delivery / customer photo.
 */
enum DeliveryDocumentType: string
{
    use HasValues;

    case DeliverySheet = 'delivery_sheet';
    case Waybill = 'waybill';
    case DeliveryPhoto = 'delivery_photo';
    case CustomerPhoto = 'customer_photo';

    public function label(): string
    {
        return match ($this) {
            self::DeliverySheet => 'Delivery sheet',
            self::Waybill => 'Waybill',
            self::DeliveryPhoto => 'Delivery photo',
            self::CustomerPhoto => 'Customer photo',
        };
    }

    /**
     * Any ONE of the returned types satisfies the requirement for the method.
     *
     * @return list<self>
     */
    public static function requiredFor(?DeliveryMethod $method): array
    {
        return match ($method) {
            DeliveryMethod::Courier => [self::Waybill],
            DeliveryMethod::OwnVehicle => [self::DeliverySheet],
            DeliveryMethod::Pickup => [self::DeliveryPhoto, self::CustomerPhoto],
            null => [],
        };
    }
}
