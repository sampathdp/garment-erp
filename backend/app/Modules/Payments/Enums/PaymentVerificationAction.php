<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\Enums\HasValues;

/** Entries of the append-only payment verification history. */
enum PaymentVerificationAction: string
{
    use HasValues;

    case Submitted = 'submitted';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
