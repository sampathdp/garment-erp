<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Enums\HasValues;

enum UrgentDecision: string
{
    use HasValues;

    case Approved = 'approved';
    case Rejected = 'rejected';
}
