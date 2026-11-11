<?php

declare(strict_types=1);

namespace App\Modules\Orders\Enums;

use App\Support\Enums\HasValues;

/**
 * Workflow status of an order ("job"). See WORKFLOW_REQUIREMENTS.md §2.
 *
 * Intake (Phase 03): a submitted order waits in one of the four pre-approval states until its
 * urgent approvals (when urgent) and its verified advance (≥ 30%) are both in place, and then
 * becomes an Approved Order (`confirmed`). Users never choose the pre-approval state:
 * EvaluateOrderRelease derives it and TransitionOrder applies it. Never update orders.status directly.
 */
enum OrderStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case PendingPaymentVerification = 'pending_payment_verification';
    case PaymentRejected = 'payment_rejected';
    case UrgentApprovalPending = 'urgent_approval_pending';
    case UrgentApprovalRejected = 'urgent_approval_rejected';
    case Confirmed = 'confirmed'; // shown as "Approved Order"
    case DesignInProgress = 'design_in_progress';
    case DesignReview = 'design_review';
    case DesignApproved = 'design_approved';
    case BulkAssigned = 'bulk_assigned';
    case BulkPreparation = 'bulk_preparation';
    case PrintPreparation = 'print_preparation';
    case PrintSampleReview = 'print_sample_review';
    case Printing = 'printing';
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case QualityCheck = 'quality_check';
    case ReadyForDelivery = 'ready_for_delivery';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    /** @return list<self> Forward / rework transitions (hold, resume and cancel handled separately). */
    public function nextStates(): array
    {
        return match ($this) {
            // Submission: urgent orders wait for approval first, the others for payment.
            self::Draft => [self::PendingPaymentVerification, self::UrgentApprovalPending],
            self::PendingPaymentVerification => [self::PaymentRejected, self::UrgentApprovalPending, self::Confirmed],
            self::PaymentRejected => [self::PendingPaymentVerification, self::UrgentApprovalPending, self::Confirmed],
            self::UrgentApprovalPending => [self::UrgentApprovalRejected, self::PendingPaymentVerification, self::PaymentRejected, self::Confirmed],
            // A rejected urgent order is released again only after its due date is changed (new evaluation).
            self::UrgentApprovalRejected => [self::UrgentApprovalPending, self::PendingPaymentVerification, self::PaymentRejected],
            self::Confirmed => [self::DesignInProgress],
            self::DesignInProgress => [self::DesignReview],
            self::DesignReview => [self::DesignInProgress, self::DesignApproved],
            self::DesignApproved => [self::BulkAssigned],
            self::BulkAssigned => [self::BulkPreparation],
            self::BulkPreparation => [self::PrintPreparation],
            self::PrintPreparation => [self::PrintSampleReview],
            self::PrintSampleReview => [self::PrintPreparation, self::Printing],
            self::Printing => [self::Cutting],
            self::Cutting => [self::Sewing],
            // Phase 07/08: the quality checks run at the cutting stage, so sewing completion releases
            // the job for delivery directly; quality_check stays for a later final inspection.
            self::Sewing => [self::QualityCheck, self::ReadyForDelivery],
            self::QualityCheck => [self::ReadyForDelivery, self::Printing, self::Cutting, self::Sewing],
            self::ReadyForDelivery => [self::OutForDelivery, self::Delivered],
            self::OutForDelivery => [self::Delivered],
            self::Delivered => [self::Completed],
            self::Completed, self::OnHold, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($target === self::OnHold) {
            return $this->isActive();
        }

        if ($target === self::Cancelled) {
            return $this->isActive() || $this === self::OnHold;
        }

        return in_array($target, $this->nextStates(), true);
    }

    /** Submitted but not released yet (waiting for payment and/or urgent approval). */
    public function isPreApproval(): bool
    {
        return in_array($this, self::preApproval(), true);
    }

    /** @return list<self> */
    public static function preApproval(): array
    {
        return [self::PendingPaymentVerification, self::PaymentRejected, self::UrgentApprovalPending, self::UrgentApprovalRejected];
    }

    /** Approved Order or any later production stage. */
    public function isReleased(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled, self::OnHold, ...self::preApproval()], true);
    }

    /** @return list<self> Approved Order and every later stage (completed and cancelled excluded). */
    public static function released(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->isReleased() && $s !== self::Completed));
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingPaymentVerification => 'Pending Payment Verification',
            self::PaymentRejected => 'Payment Rejected',
            self::UrgentApprovalPending => 'Urgent Approval Pending',
            self::UrgentApprovalRejected => 'Urgent Approval Rejected',
            self::Confirmed => 'Approved Order',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }

    /** Cancelling from printing onwards needs manager approval (orders.cancel_late). */
    public function requiresLateCancellation(): bool
    {
        return $this->stageIndex() >= self::Printing->stageIndex();
    }

    public function isActive(): bool
    {
        return ! in_array($this, [self::Completed, self::Cancelled, self::OnHold], true);
    }

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    /** "Assigned department" is derived from the status, not stored. */
    public function department(): Department
    {
        return match ($this) {
            self::Draft, self::UrgentApprovalPending, self::UrgentApprovalRejected => Department::Sales,
            self::PendingPaymentVerification, self::PaymentRejected => Department::Accounts,
            self::Confirmed, self::DesignInProgress, self::DesignReview => Department::Design,
            self::DesignApproved, self::BulkAssigned, self::BulkPreparation => Department::Production,
            self::PrintPreparation, self::PrintSampleReview, self::Printing => Department::Printing,
            self::Cutting => Department::Cutting,
            self::Sewing => Department::Sewing,
            self::QualityCheck => Department::QualityControl,
            self::ReadyForDelivery, self::OutForDelivery, self::Delivered => Department::Dispatch,
            self::Completed, self::OnHold, self::Cancelled => Department::Management,
        };
    }

    /** @return list<self> statuses handled by a department (for "assigned department" filters). */
    public static function forDepartment(Department $department): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s->department() === $department));
    }

    private function stageIndex(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
