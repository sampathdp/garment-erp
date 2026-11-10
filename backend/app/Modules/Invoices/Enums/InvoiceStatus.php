<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Enums;

use App\Support\Enums\HasValues;

enum InvoiceStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Issued = 'issued';
    case Void = 'void';
}
