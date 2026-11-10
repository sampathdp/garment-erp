<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

use App\Support\Enums\HasValues;

enum PaymentMethod: string
{
    use HasValues;

    case BankTransfer = 'bank_transfer';
    case Cash = 'cash';
    case Card = 'card';
    case Online = 'online';
    case Cheque = 'cheque';
    case Cod = 'cod';
}
