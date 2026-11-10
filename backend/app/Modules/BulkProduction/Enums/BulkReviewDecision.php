<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

enum BulkReviewDecision: string
{
    use HasValues;

    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
