<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Enums\HasValues;

/** Financial status, derived from verified payments and maintained in the same transaction. */
enum OrderPaymentStatus: string
{
    use HasValues;

    case Unpaid = 'unpaid';
    case AdvancePending = 'advance_pending';
    case AdvancePaid = 'advance_paid';
    case Paid = 'paid';
    case Refunded = 'refunded';
}
