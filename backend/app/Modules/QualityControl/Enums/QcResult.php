<?php

declare(strict_types=1);

namespace App\Modules\QualityControl\Enums;

use App\Support\Enums\HasValues;

enum QcResult: string
{
    use HasValues;

    case Pass = 'pass';
    case Fail = 'fail';
    case Partial = 'partial';
}
