<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Enums;

use App\Support\Enums\HasValues;

/** Garment part named in a "missing parts" report (missing sleeves, collars ...). */
enum GarmentPart: string
{
    use HasValues;

    case Sleeve = 'sleeve';
    case Collar = 'collar';
    case Front = 'front';
    case Back = 'back';
    case Cuff = 'cuff';
    case Pocket = 'pocket';
    case Waistband = 'waistband';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
