<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Enums;

use App\Support\Enums\HasValues;

enum CodStatus: string
{
    use HasValues;

    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Collected = 'collected';
    case Remitted = 'remitted';
    case Failed = 'failed';
}
