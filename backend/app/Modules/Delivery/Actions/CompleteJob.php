<?php

declare(strict_types=1);

namespace App\Modules\Delivery\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Support\DeliveryNotifications;
use App\Modules\Delivery\Support\DeliveryWorkflow;
use App\Modules\Delivery\Support\JobCompletion;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Final job completion (Phase 08 §3). Every rule of JobCompletion is re-checked under the order +
 * delivery locks; any blocker → 409 `job_incomplete` listing what is missing by group. Records
 * who completed the job, when and the notes, then moves the order delivered → completed.
 */
final class CompleteJob
{
    public function __construct(
        private readonly DeliveryWorkflow $workflow,
        private readonly JobCompletion $completion,
        private readonly TransitionOrder $transition,
        private readonly DeliveryNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, Delivery $delivery, int $expectedVersion, ?string $notes): Delivery
    {
        return DB::transaction(function () use ($actor, $delivery, $expectedVersion, $notes): Delivery {
            [$delivery, $order] = $this->workflow->lock($delivery, $expectedVersion);
            if ($order->status === OrderStatus::Completed) {
                throw ApiException::conflict('already_completed', 'This job is already completed.');
            }
            $blockers = $this->completion->blockers($order, $delivery);
            if ($blockers !== []) {
                throw new ApiException(409, 'job_incomplete', 'The job cannot be completed yet.', $blockers);
            }

            $order->forceFill(['completed_at' => now(), 'completed_by' => $actor->id, 'completion_notes' => $notes]);
            $this->transition->handle($order, OrderStatus::Completed, $actor, 'Job completed.');
            $this->workflow->save($delivery);
            $this->audit->record('orders.completed', $order, ['after' => ['completed_by' => $actor->id, 'notes' => $notes, 'delivery_id' => $delivery->id,
                'total_minor' => $order->total_minor, 'verified_paid_minor' => $order->verified_paid_minor]], actor: $actor, branchId: $order->branch_id);
            $this->notify->completed($delivery, $order, $actor);

            return $delivery;
        });
    }
}
