<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum IssueType: string
{
    use HasValues;

    case CorrectionRequest = 'correction_request';
    case Material = 'material';
    case Machine = 'machine';
    case Quality = 'quality';
    case Other = 'other';
}
