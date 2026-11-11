<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Enums;

use App\Support\Enums\HasValues;

/**
 * Cutting job statuses (Phase 07). Changed only through CuttingWorkflow::transition(), which
 * enforces the transitions below and writes a cutting_job_events row.
 *
 *   pending_assignment → assigned → in_progress → completed
 *
 * The first recorded work (quantities, checklist, quality check) moves assigned → in_progress.
 */
enum CuttingJobStatus: string
{
    use HasValues;

    case PendingAssignment = 'pending_assignment';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    /** @return list<self> */
    public function nextStates(): array
    {
        return match ($this) {
            self::PendingAssignment => [self::Assigned],
            self::Assigned => [self::InProgress],
            self::InProgress => [self::Completed],
            self::Completed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingAssignment => 'Pending Assignment',
            self::Assigned => 'Assigned',
            self::InProgress => 'Cutting In Progress',
            self::Completed => 'Cutting Completed',
        };
    }

    /** Work (quantities, checklist, quality checks) can be recorded once a cutter is assigned. */
    public function acceptsWork(): bool
    {
        return $this === self::Assigned || $this === self::InProgress;
    }

    public function isOpen(): bool
    {
        return $this !== self::Completed;
    }
}
