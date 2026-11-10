<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Enums;

use App\Support\Enums\HasValues;

enum DefectAction: string
{
    use HasValues;

    case Reprint = 'reprint';
    case Recut = 'recut';
    case Resew = 'resew';
    case Repair = 'repair';
    case Scrap = 'scrap';
    case Accept = 'accept';
}
