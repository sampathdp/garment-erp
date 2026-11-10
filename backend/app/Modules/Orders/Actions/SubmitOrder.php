<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Support\OrderNotifier;
use App\Modules\Orders\Support\UrgencyRule;
use App\Support\Exceptions\ApiException;
use App\Support\Time\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Draft → Pending Payment Verification (normal) or Urgent Approval Pending (fewer than 7 days).
 * Urgency is evaluated here from the server date; nothing the client sends can skip it.
 */
final class SubmitOrder
{
    public function __construct(
        private readonly ApplyUrgencyRule $urgency,
        private readonly TransitionOrder $transition,
        private readonly OrderNotifier $notifier,
    ) {}

    public function handle(User $actor, Order $order, int $expectedVersion): Order
    {
        return DB::transaction(function () use ($actor, $order, $expectedVersion): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->version !== $expectedVersion) {
                throw ApiException::conflict('version_conflict', 'This order was changed by someone else. Reload it and try again.');
            }

            return $this->submitLocked($actor, $order);
        });
    }

    /** For callers that already hold the order lock (CreateOrder with submit=true). */
    public function submitLocked(User $actor, Order $order): Order
    {
        if ($order->status !== OrderStatus::Draft) {
            throw ApiException::conflict('invalid_transition', 'Only draft orders can be submitted.');
        }

        if ($order->total_minor <= 0) {
            throw new ApiException(422, 'validation_failed', 'Enter the unit price before submitting the order.', ['unit_price_minor' => ['A price is required to calculate the 30% advance.']]);
        }

        if ($order->required_delivery_date->format('Y-m-d') <= BusinessDate::today()->toDateString()) {
            throw new ApiException(422, 'validation_failed', 'The due date must be after today.', ['due_date' => ['The due date must be after today.']]);
        }

        $result = $this->urgency->handle($order, $actor);
        $order->forceFill(['submitted_at' => now()])->save();

        $target = $result['urgent'] ? OrderStatus::UrgentApprovalPending : OrderStatus::PendingPaymentVerification;
        $this->transition->handle($order, $target, $actor, $result['urgent']
            ? "Submitted. Due in {$result['days']} day(s), fewer than ".UrgencyRule::THRESHOLD_DAYS.': urgent approval required.'
            : 'Submitted.');

        $this->notifier->orderCreated($order, $actor);
        if ($result['urgent']) {
            $this->notifier->urgentRequested($order, $result['days']);
        }

        return $order;
    }
}
