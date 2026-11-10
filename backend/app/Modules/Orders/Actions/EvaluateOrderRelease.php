<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Queries\OrderPaymentSummary;

/**
 * Re-derives an order's payment figures and, for submitted orders that are not released yet,
 * its pre-approval status. Called (with the order row locked) after submission, every payment
 * recorded or decided, every urgent decision and every due-date or price change.
 *
 * Release rule (business invariant 3 + urgent rule):
 *   urgent request rejected             → Urgent Approval Rejected
 *   urgent request waiting              → Urgent Approval Pending
 *   verified payments ≥ required advance → Approved Order (confirmed)
 *   a payment is waiting for checking    → Pending Payment Verification
 *   the latest decision was a rejection  → Payment Rejected
 *   otherwise                            → Pending Payment Verification (advance still missing)
 *
 * Verification and eligibility are separate: a VERIFIED payment below 30% keeps the order out
 * of production, with the shortfall shown on the order.
 */
final class EvaluateOrderRelease
{
    public function __construct(
        private readonly OrderPaymentSummary $payments,
        private readonly TransitionOrder $transition,
    ) {}

    /** @return array{from: OrderStatus, to: OrderStatus, summary: array{verified_minor: int, pending_minor: int, pending_count: int, last_decision: PaymentStatus|null}} */
    public function handle(Order $order, ?User $actor, string $reason): array
    {
        $summary = $this->payments->forOrder($order->id);
        $this->syncPaymentFigures($order, $summary);

        $from = $order->status;
        // Drafts are released only by SubmitOrder; released orders never fall back.
        if (! $from->isPreApproval()) {
            return ['from' => $from, 'to' => $from, 'summary' => $summary];
        }

        $to = $this->target($order, $summary);

        // A rejected urgent order is re-released only through a new evaluation (due-date change);
        // when the new evaluation releases it straight away, it passes through the payment state.
        if ($to !== $from && ! $from->canTransitionTo($to) && $from->canTransitionTo(OrderStatus::PendingPaymentVerification)) {
            $this->transition->handle($order, OrderStatus::PendingPaymentVerification, $actor, $reason);
        }
        $this->transition->handle($order, $to, $actor, $reason);

        return ['from' => $from, 'to' => $to, 'summary' => $summary];
    }

    /** @param  array{verified_minor: int, pending_minor: int, pending_count: int, last_decision: PaymentStatus|null}  $summary */
    public function target(Order $order, array $summary): OrderStatus
    {
        if ($order->is_urgent && $order->urgent_status === UrgentStatus::Rejected) {
            return OrderStatus::UrgentApprovalRejected;
        }

        if ($order->is_urgent && $order->urgent_status !== UrgentStatus::Approved) {
            return OrderStatus::UrgentApprovalPending;
        }

        if ($order->meetsAdvanceRequirement()) {
            return OrderStatus::Confirmed;
        }

        if ($summary['pending_count'] === 0 && $summary['last_decision'] === PaymentStatus::Rejected) {
            return OrderStatus::PaymentRejected;
        }

        return OrderStatus::PendingPaymentVerification;
    }

    /** @param  array{verified_minor: int, pending_minor: int, pending_count: int, last_decision: PaymentStatus|null}  $summary */
    private function syncPaymentFigures(Order $order, array $summary): void
    {
        $verified = $summary['verified_minor'];
        $status = match (true) {
            $order->total_minor > 0 && $verified >= $order->total_minor => OrderPaymentStatus::Paid,
            $order->total_minor > 0 && $verified >= $order->advance_required_minor => OrderPaymentStatus::AdvancePaid,
            $verified > 0 || $summary['pending_count'] > 0 => OrderPaymentStatus::AdvancePending,
            default => OrderPaymentStatus::Unpaid,
        };

        $order->forceFill(['verified_paid_minor' => $verified, 'payment_status' => $status]);
        if ($order->isDirty()) {
            $order->save();
        }
    }
}
