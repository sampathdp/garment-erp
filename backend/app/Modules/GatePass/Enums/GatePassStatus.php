<?php

declare(strict_types=1);

namespace App\Modules\GatePass\Enums;

use App\Support\Enums\HasValues;

enum GatePassStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Approved = 'approved';
    case CheckedOut = 'checked_out';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
