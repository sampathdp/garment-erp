<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\Enums\HasValues;

enum PaymentStatus: string
{
    use HasValues;

    case PendingVerification = 'pending_verification';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Reversed = 'reversed';
}
