<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum BulkDocumentKind: string
{
    use HasValues;

    case Evidence = 'evidence';                      // external production evidence
    case ClientConfirmation = 'client_confirmation'; // customer's checklist confirmation (screenshot, ...)
}
