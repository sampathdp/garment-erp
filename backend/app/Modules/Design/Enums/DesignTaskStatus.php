<?php

declare(strict_types=1);

namespace App\Modules\Design\Enums;

use App\Support\Enums\HasValues;

/**
 * pending            created, no version submitted yet (assigned or waiting for a designer)
 * in_progress        designer working (reserved; a submission moves straight to in_review)
 * in_review          a version is waiting for the Page Owner's review
 * changes_requested  the Page Owner asked for a correction (counts a correction round)
 * approved           final design approved; waiting for the final PDF/CDR or Drive link
 * completed          final files submitted: the design task is complete
 */
enum DesignTaskStatus: string
{
    use HasValues;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for first design',
            self::InProgress => 'In progress',
            self::InReview => 'Waiting for review',
            self::ChangesRequested => 'Correction requested',
            self::Approved => 'Approved — final files due',
            self::Completed => 'Completed',
        };
    }
}
