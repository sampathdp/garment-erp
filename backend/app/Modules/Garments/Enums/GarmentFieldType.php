<?php

declare(strict_types=1);

namespace App\Modules\Garments\Enums;

use App\Support\Enums\HasValues;

enum GarmentFieldType: string
{
    use HasValues;

    case Text = 'text';
    case Number = 'number';
    case Select = 'select';
    case Boolean = 'boolean';
}
