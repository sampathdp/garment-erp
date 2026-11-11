<?php

declare(strict_types=1);

namespace App\Modules\Printing\Enums;

use App\Support\Enums\HasValues;

/**
 * Printing job statuses (Phase 06). Changed only through PrintingWorkflow::transition(), which
 * enforces the transitions below and writes a print_job_events row.
 *
 *   ready_for_printing → sent_for_printing → sample_pending_approval ⇄ sample_rejected
 *   sample_pending_approval → sample_approved → physical_printing → printing_completed
 */
enum PrintJobStatus: string
{
    use HasValues;

    case ReadyForPrinting = 'ready_for_printing';
    case SentForPrinting = 'sent_for_printing';
    case SamplePendingApproval = 'sample_pending_approval';
    case SampleRejected = 'sample_rejected';
    case SampleApproved = 'sample_approved';
    case PhysicalPrinting = 'physical_printing';
    case PrintingCompleted = 'printing_completed';
    case Invalidated = 'invalidated'; // design or list changed after preparation (reserved)

    /** @return list<self> */
    public function nextStates(): array
    {
        return match ($this) {
            self::ReadyForPrinting => [self::SentForPrinting],
            self::SentForPrinting => [self::SamplePendingApproval],
            self::SamplePendingApproval => [self::SampleApproved, self::SampleRejected],
            self::SampleRejected => [self::SamplePendingApproval],
            self::SampleApproved => [self::PhysicalPrinting],
            self::PhysicalPrinting => [self::PrintingCompleted],
            self::PrintingCompleted, self::Invalidated => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->nextStates(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ReadyForPrinting => 'Ready for Printing',
            self::SentForPrinting => 'Sent for Printing',
            self::SamplePendingApproval => 'Sample Pending Approval',
            self::SampleRejected => 'Sample Rejected',
            self::SampleApproved => 'Sample Approved',
            self::PhysicalPrinting => 'Physical Printing In Progress',
            self::PrintingCompleted => 'Printing Completed',
            self::Invalidated => 'Invalidated',
        };
    }

    /** A printing sample can be (re)submitted: after sending, or after a rejection. */
    public function acceptsSample(): bool
    {
        return $this === self::SentForPrinting || $this === self::SampleRejected;
    }

    /**
     * The Job Card and the name/number list may be PRINTED only from the explicitly approved
     * production stage onwards: the Page Owner approved the printing sample [ASSUMPTION, Q29].
     */
    public function allowsDocumentPrinting(): bool
    {
        return in_array($this, [self::SampleApproved, self::PhysicalPrinting, self::PrintingCompleted], true);
    }

    public function isClosed(): bool
    {
        return $this === self::PrintingCompleted || $this === self::Invalidated;
    }
}
