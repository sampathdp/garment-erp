<?php

declare(strict_types=1);

namespace App\Modules\Design\Enums;

use App\Support\Enums\HasValues;

enum ReviewDecision: string
{
    use HasValues;

    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';
}
