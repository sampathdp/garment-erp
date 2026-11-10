<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

/** Status of bulk assignments, cutting jobs and sewing jobs. */
enum WorkStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
