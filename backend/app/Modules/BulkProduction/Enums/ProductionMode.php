<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum ProductionMode: string
{
    use HasValues;

    case Internal = 'internal';
    case External = 'external';
}
