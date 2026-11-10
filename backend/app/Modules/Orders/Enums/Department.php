<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Enums\HasValues;

enum Department: string
{
    use HasValues;

    case Sales = 'sales';
    case Accounts = 'accounts';
    case Design = 'design';
    case Production = 'production';
    case Printing = 'printing';
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case QualityControl = 'quality_control';
    case Dispatch = 'dispatch';
    case Management = 'management';
}
