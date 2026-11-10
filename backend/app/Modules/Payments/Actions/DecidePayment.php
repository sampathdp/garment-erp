<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\EvaluateOrderRelease;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use App\Modules\Orders\Support\OrderNotifier;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentVerificationAction;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentVerificationEvent;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Second Admin confirms or rejects a payment (PaymentPolicy checked scope, permission, state and
 * separation of duties; re-checked here under the lock). A decision is final: a corrected slip
 * is recorded as a new payment.
 *
 * Confirming a payment does NOT by itself make the job eligible for production: eligibility is
 * re-derived from the sum of verified payments against the 30% advance (EvaluateOrderRelease).
 */
final class DecidePayment
{
    public function __construct(
        private readonly EvaluateOrderRelease $release,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly CreateBulkJobIfEligible $bulk,
    ) {}

    public function handle(User $actor, Payment $payment, bool $verify, ?string $notes): Payment
    {
        return DB::transaction(function () use ($actor, $payment, $verify, $notes): Payment {
            // Lock order first, then payment (same order everywhere: no deadlocks with RecordPayment).
            $order = Order::query()->lockForUpdate()->findOrFail($payment->order_id);
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status !== PaymentStatus::PendingVerification) {
                throw ApiException::conflict('invalid_state', 'This payment has already been decided.');
            }
            if ((int) $payment->getAttribute('recorded_by') === $actor->id) {
                throw ApiException::forbidden('separation_of_duties', 'You cannot verify a payment you recorded.');
            }

            $payment->forceFill([
                'status' => $verify ? PaymentStatus::Verified : PaymentStatus::Rejected,
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'verification_notes' => $verify ? $notes : null,
                'rejection_reason' => $verify ? null : $notes,
            ])->save();

            PaymentVerificationEvent::query()->create([
                'payment_id' => $payment->id,
                'action' => $verify ? PaymentVerificationAction::Verified : PaymentVerificationAction::Rejected,
                'notes' => $notes, 'actor_id' => $actor->id,
            ]);
            $this->audit->record($verify ? 'payments.verified' : 'payments.rejected', $payment, [
                'before' => ['status' => PaymentStatus::PendingVerification->value],
                'after' => ['status' => $payment->status->value, 'notes' => $notes],
            ], actor: $actor);

            $result = $this->release->handle($order, $actor, $verify ? 'Payment verified.' : "Payment rejected: {$notes}");

            $this->notifier->paymentDecided($order, $payment->id, (int) $payment->getAttribute('recorded_by'), $verify, $notes, $actor);

            // Verified but still short of the 30% advance, with nothing else waiting for checking.
            if ($verify && ! $order->meetsAdvanceRequirement() && $result['summary']['pending_count'] === 0) {
                $this->notifier->advanceBelowMinimum($order, $this->operationsApprover($order), notifyOperations: $order->status->isReleased());
            }

            // A top-up after the invoice can complete the bulk prerequisites.
            if ($verify && $order->status === OrderStatus::DesignApproved) {
                $this->bulk->handle($order, $actor);
            }

            return $payment;
        });
    }

    /** The Operation Admin who already approved this order as urgent (they planned production around it). */
    private function operationsApprover(Order $order): ?int
    {
        if (! $order->is_urgent) {
            return null;
        }

        $id = UrgentApprovalDecision::query()
            ->whereHas('urgentApproval', fn ($q) => $q->where('order_id', $order->id)->whereNull('superseded_at'))
            ->where('slot', UrgentApprovalSlot::OperationAdmin->value)
            ->where('decision', UrgentDecision::Approved->value)
            ->value('decided_by');

        return $id === null ? null : (int) $id;
    }
}
