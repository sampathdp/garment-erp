<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum SupplierService: string
{
    use HasValues;

    case Printing = 'printing';
    case Sewing = 'sewing';
    case Cutting = 'cutting';
    case Full = 'full';
}
