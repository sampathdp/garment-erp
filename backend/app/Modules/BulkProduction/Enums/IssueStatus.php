<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum IssueStatus: string
{
    use HasValues;

    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
