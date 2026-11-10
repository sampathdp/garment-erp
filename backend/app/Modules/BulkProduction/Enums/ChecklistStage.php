<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum ChecklistStage: string
{
    use HasValues;

    case BulkPreparation = 'bulk_preparation'; // internal bulk (Bulk Designer)
    case BulkExternal = 'bulk_external';       // external bulk (recorded by the Operation Admin)
    case PrintPreparation = 'print_preparation';
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case QualityControl = 'quality_control';
    case Dispatch = 'dispatch';
}
