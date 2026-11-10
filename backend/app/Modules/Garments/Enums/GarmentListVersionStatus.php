<?php

declare(strict_types=1);

namespace App\Modules\Garments\Enums;

use App\Support\Enums\HasValues;

enum GarmentListVersionStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Locked = 'locked';
    case Superseded = 'superseded';
}
