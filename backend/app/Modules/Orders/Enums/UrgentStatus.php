<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Enums\HasValues;

enum UrgentStatus: string
{
    use HasValues;

    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
