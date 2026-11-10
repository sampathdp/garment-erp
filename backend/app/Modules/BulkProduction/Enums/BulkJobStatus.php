<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Enums;

use App\Support\Enums\HasValues;

/**
 * Bulk job lifecycle (WORKFLOW_REQUIREMENTS.md §3.7b).
 *
 *   internal: pending_assignment → assigned → in_progress → in_review ⇄ changes_requested → approved → completed
 *   external: pending_assignment → assigned → in_progress → completed (recorded by the Operation Admin)
 */
enum BulkJobStatus: string
{
    use HasValues;

    case PendingAssignment = 'pending_assignment';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PendingAssignment => 'Pending assignment',
            self::InReview => 'In review',
            self::ChangesRequested => 'Correction requested',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** Measurements and checklist can be worked on (frozen while in review and once closed). */
    public function acceptsWork(ProductionMode $mode): bool
    {
        return $mode === ProductionMode::Internal
            ? in_array($this, [self::Assigned, self::InProgress, self::ChangesRequested, self::Approved], true)
            : in_array($this, [self::Assigned, self::InProgress], true);
    }
}
