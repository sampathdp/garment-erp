<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum ChecklistResult: string
{
    use HasValues;

    case Pass = 'pass';
    case Fail = 'fail';
    case NotApplicable = 'na';
}
