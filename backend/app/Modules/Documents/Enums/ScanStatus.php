<?php

declare(strict_types=1);

namespace App\Modules\Documents\Enums;

use App\Support\Enums\HasValues;

enum ScanStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
}
