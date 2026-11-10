<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Enums;

use App\Support\Enums\HasValues;

enum InvoiceType: string
{
    use HasValues;

    case Proforma = 'proforma';
    case AdvanceReceipt = 'advance_receipt';
    case Final = 'final';
    case CreditNote = 'credit_note';
}
