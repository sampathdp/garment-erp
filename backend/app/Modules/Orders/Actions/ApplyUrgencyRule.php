<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\UrgentApproval;
use App\Modules\Orders\Support\UrgencyRule;
use App\Support\Time\BusinessDate;

/**
 * Evaluates the seven-day rule on the server (never trusted from the client) when an order is
 * submitted and whenever its due date changes. The previous request (with its decisions) is
 * kept as history and marked superseded; an urgent result opens a new request, so approvals
 * given for an earlier due date never carry over.
 *
 * Caller holds the order lock inside its transaction.
 */
final class ApplyUrgencyRule
{
    public function __construct(private readonly UrgencyRule $rule, private readonly AuditLogger $audit) {}

    /** @return array{days: int, urgent: bool, request: UrgentApproval|null} */
    public function handle(Order $order, User $actor): array
    {
        $reference = BusinessDate::today();
        $due = BusinessDate::parse($order->required_delivery_date->format('Y-m-d'));
        $result = $this->rule->evaluate($reference, $due, $order->branch_id);

        UrgentApproval::query()->where('order_id', $order->id)->whereNull('superseded_at')->update(['superseded_at' => now()]);

        $request = null;
        if ($result['urgent']) {
            $request = UrgentApproval::query()->create([
                'order_id' => $order->id,
                'evaluated_delivery_date' => $due->toDateString(),
                'reference_date' => $reference->toDateString(),
                'working_days_available' => $result['days'],
                'requested_at' => now(),
            ]);
        }

        $order->forceFill([
            'is_urgent' => $result['urgent'],
            'urgent_status' => $result['urgent'] ? UrgentStatus::Pending : UrgentStatus::NotRequired,
        ])->save();

        $this->audit->record('orders.urgency_evaluated', $order, ['after' => [
            'reference_date' => $reference->toDateString(), 'due_date' => $due->toDateString(),
            'days' => $result['days'], 'urgent' => $result['urgent'],
        ]], actor: $actor);

        return [...$result, 'request' => $request];
    }
}
