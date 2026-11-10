<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ONLY place where orders.status changes (business invariant 5).
 * The caller holds the order row lock (lockForUpdate) inside its transaction and has checked
 * the expected version; this action validates the transition, bumps the version and writes
 * the history row and the audit entry.
 */
final class TransitionOrder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Order $order, OrderStatus $to, ?User $actor, ?string $reason = null): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Order transitions must run inside a transaction holding the order lock.');
        }

        $from = $order->status;
        if ($from === $to) {
            return;
        }

        if (! $from->canTransitionTo($to)) {
            throw ApiException::conflict('invalid_transition', "The order cannot move from {$from->label()} to {$to->label()}.");
        }

        $order->forceFill(['status' => $to, 'version' => $order->version + 1])->save();

        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'actor_id' => $actor?->id,
        ]);

        $this->audit->record('orders.status_changed', $order, [
            'before' => ['status' => $from->value],
            'after' => ['status' => $to->value, 'reason' => $reason],
        ], actor: $actor);
    }
}
