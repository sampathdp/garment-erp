<?php

declare(strict_types=1);

namespace App\Modules\Payments\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Documents\Actions\StoreDocument;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\EvaluateOrderRelease;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderNotifier;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\PaymentVerificationAction;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentVerificationEvent;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Records an advance (or later balance) payment with its evidence. It counts towards the 30%
 * advance only after a DIFFERENT user (Second Admin) verifies it. Supports Idempotency-Key, so a
 * double submit returns the first payment instead of recording two.
 */
final class RecordPayment
{
    public function __construct(
        private readonly StoreDocument $documents,
        private readonly EvaluateOrderRelease $release,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array{amount_minor: int, method: PaymentMethod, reference_no: string|null, payment_date: string, notes: string|null}  $input */
    public function handle(User $actor, Order $order, array $input, ?UploadedFile $evidence, ?string $idempotencyKey): Payment
    {
        if ($idempotencyKey !== null && ($existing = $this->replay($actor, $order, $idempotencyKey)) !== null) {
            return $existing;
        }

        $stored = $evidence !== null ? $this->documents->put($evidence, DocumentCategory::PaymentSlip) : null;

        try {
            return DB::transaction(function () use ($actor, $order, $input, $stored, $idempotencyKey): Payment {
                $order = Order::query()->lockForUpdate()->findOrFail($order->id);
                if ($order->status === OrderStatus::Draft || ! $order->status->isActive()) {
                    throw ApiException::conflict('invalid_state', 'Payments can be recorded after the order is submitted and while it is active.');
                }

                $outstanding = $order->total_minor - $this->openAmount($order->id);
                if ($input['amount_minor'] > $outstanding) {
                    throw new ApiException(422, 'validation_failed', 'The amount is more than the unpaid balance.', [
                        'amount_minor' => ['The amount is more than the unpaid balance including payments waiting for verification.'],
                    ]);
                }

                $document = $stored === null ? null : $this->documents->record($stored, DocumentCategory::PaymentSlip, $actor, $order->branch_id);

                $payment = new Payment([
                    'order_id' => $order->id,
                    'amount_minor' => $input['amount_minor'],
                    'currency' => $order->currency,
                    'method' => $input['method'],
                    'reference_no' => $input['reference_no'],
                    // Business date (Asia/Colombo) stored as the start of that day in UTC.
                    'paid_at' => BusinessDate::parse($input['payment_date'])->utc(),
                    'slip_document_id' => $document?->id,
                ]);
                $payment->forceFill([
                    'branch_id' => $order->branch_id,
                    'status' => PaymentStatus::PendingVerification,
                    'recorded_by' => $actor->id,
                    'idempotency_key' => $idempotencyKey,
                ])->save();

                PaymentVerificationEvent::query()->create(['payment_id' => $payment->id, 'action' => PaymentVerificationAction::Submitted, 'notes' => $input['notes'], 'actor_id' => $actor->id]);
                $this->audit->record('payments.recorded', $payment, ['after' => [
                    'order_id' => $order->id, 'amount_minor' => $payment->amount_minor, 'method' => $payment->method->value,
                    'reference_no' => $payment->reference_no, 'evidence_document_id' => $document?->id,
                ]], actor: $actor);

                $this->release->handle($order, $actor, 'Payment recorded, waiting for verification.');
                $this->notifier->paymentPending($order, $payment->id, $payment->amount_minor, $actor);

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            // Two requests with the same key raced: return the one that won.
            if ($idempotencyKey !== null && ($existing = $this->replay($actor, $order, $idempotencyKey)) !== null) {
                return $existing;
            }
            throw $e;
        } catch (Throwable $e) {
            if ($stored !== null) {
                $this->documents->discard($stored);
            }
            throw $e;
        }
    }

    private function replay(User $actor, Order $order, string $key): ?Payment
    {
        $existing = Payment::query()->where('idempotency_key', $key)->first();
        if ($existing === null) {
            return null;
        }
        if ($existing->order_id !== $order->id || (int) $existing->getAttribute('recorded_by') !== $actor->id) {
            throw ApiException::conflict('idempotency_conflict', 'This Idempotency-Key was already used for a different request.');
        }

        return $existing;
    }

    /** Verified plus waiting payments (rejected ones do not count). */
    private function openAmount(int $orderId): int
    {
        return (int) Payment::query()->where('order_id', $orderId)
            ->whereIn('status', [PaymentStatus::Verified->value, PaymentStatus::PendingVerification->value])
            ->sum('amount_minor');
    }
}
