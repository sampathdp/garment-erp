<?php

declare(strict_types=1);

namespace App\Modules\Design\Enums;

use App\Support\Enums\HasValues;

/** Channel through which the customer gave a decision. Customers do not log in. */
enum ApprovalChannel: string
{
    use HasValues;

    case Whatsapp = 'whatsapp';
    case Messenger = 'messenger';
    case Phone = 'phone';
    case InPerson = 'in_person';
    case Email = 'email';
}
