<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

enum SampleDecision: string
{
    use HasValues;

    case Approved = 'approved';
    case Rejected = 'rejected';
}
