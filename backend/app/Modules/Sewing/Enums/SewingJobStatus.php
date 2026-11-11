<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Enums;

use App\Support\Enums\HasValues;

/** Minimal sewing step (Phase 08): pending → in_progress → completed. */
enum SewingJobStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for Sewing',
            self::InProgress => 'Sewing In Progress',
            self::Completed => 'Sewing Completed',
        };
    }
}
