<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentApprovalSlot;
use App\Modules\Orders\Enums\UrgentDecision;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\UrgentApproval;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use App\Modules\Orders\Support\OrderNotifier;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Records one slot's decision on an urgent order (OrderPolicy::decideUrgent checked scope,
 * permission and state). Rules, enforced again under the order lock:
 *  - one decision per slot per request, and the two slots must be decided by different people;
 *  - any rejection rejects the request (Urgent Approval Rejected);
 *  - the job is released only when BOTH slots approved (and the advance is verified).
 */
final class DecideUrgentApproval
{
    public function __construct(
        private readonly EvaluateOrderRelease $release,
        private readonly OrderNotifier $notifier,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, Order $order, UrgentApprovalSlot $slot, UrgentDecision $decision, ?string $reason): Order
    {
        return DB::transaction(function () use ($actor, $order, $slot, $decision, $reason): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== OrderStatus::UrgentApprovalPending || $order->urgent_status !== UrgentStatus::Pending) {
                throw ApiException::conflict('invalid_state', 'This order is not waiting for an urgent approval.');
            }

            $request = UrgentApproval::query()->where('order_id', $order->id)->whereNull('superseded_at')->whereNull('decision')
                ->latest('id')->with('decisions')->first()
                ?? throw ApiException::conflict('invalid_state', 'This order has no open urgent approval request.');

            if ($request->decisions->contains(fn (UrgentApprovalDecision $d) => $d->slot === $slot)) {
                throw ApiException::conflict('already_decided', "The {$slot->label()} decision has already been recorded.");
            }
            if ($request->decisions->contains(fn (UrgentApprovalDecision $d) => $d->decided_by === $actor->id)) {
                throw ApiException::forbidden('separation_of_duties', 'The two urgent approvals must be given by different people.');
            }

            UrgentApprovalDecision::query()->create([
                'urgent_approval_id' => $request->id, 'slot' => $slot, 'decision' => $decision,
                'reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(),
            ]);

            $approvedSlots = $request->decisions->where('decision', UrgentDecision::Approved)->pluck('slot')->push($decision === UrgentDecision::Approved ? $slot : null)->filter()->unique();
            $outcome = match (true) {
                $decision === UrgentDecision::Rejected => UrgentDecision::Rejected,
                $approvedSlots->count() === count(UrgentApprovalSlot::cases()) => UrgentDecision::Approved,
                default => null,
            };

            if ($outcome !== null) {
                $request->forceFill(['decision' => $outcome, 'decided_by' => $actor->id, 'decided_at' => now(), 'reason' => $reason])->save();
                $order->forceFill(['urgent_status' => $outcome === UrgentDecision::Approved ? UrgentStatus::Approved : UrgentStatus::Rejected])->save();
            }

            $this->audit->record('orders.urgent_decision', $order, ['after' => [
                'request_id' => $request->id, 'slot' => $slot->value, 'decision' => $decision->value, 'reason' => $reason, 'outcome' => $outcome?->value,
            ]], actor: $actor);

            $result = $this->release->handle($order, $actor, match ($outcome) {
                UrgentDecision::Approved => 'Urgent job approved by the Second Admin and the Operation Admin.',
                UrgentDecision::Rejected => "Urgent job rejected by the {$slot->label()}: {$reason}",
                null => "{$slot->label()} approved the urgent job.",
            });

            if ($outcome === null) {
                $this->notifier->urgentSlotApproved($order, $slot, $actor);
            } else {
                $this->notifier->urgentDecided($order, $outcome === UrgentDecision::Approved, $reason);
                if ($outcome === UrgentDecision::Approved && ! $order->meetsAdvanceRequirement() && $result['summary']['pending_count'] === 0) {
                    $earlier = $request->decisions->first(fn (UrgentApprovalDecision $d) => $d->slot === UrgentApprovalSlot::OperationAdmin);
                    $operations = $earlier !== null ? (int) $earlier->decided_by : ($slot === UrgentApprovalSlot::OperationAdmin ? $actor->id : null);
                    $this->notifier->advanceBelowMinimum($order, $operations);
                }
            }

            return $order;
        });
    }
}
