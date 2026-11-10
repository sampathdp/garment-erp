<?php

declare(strict_types=1);

namespace App\Modules\Design\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Enums\ReviewDecision;
use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignReview;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Support\DesignNotifications;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Support\WorkflowSettings;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Page Owner review of the version in review (customers do not log in; the Page Owner records
 * the customer's decision with its channel):
 *
 *  - requestCorrection: a traceable correction record (round n of the configurable limit, default 3).
 *    Beyond the limit only users with designs.correction_override can continue, with a reason.
 *  - approve: the final design approval (policy: Page Owner of the page). The designer is then
 *    asked for the final PDF/CDR or Drive link; the task is completed only when that arrives.
 */
final class ReviewDesign
{
    public function __construct(
        private readonly WorkflowSettings $settings,
        private readonly TransitionOrder $transition,
        private readonly DesignNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function requestCorrection(User $actor, DesignTask $task, string $reason, ApprovalChannel $channel, ?string $overrideReason): DesignTask
    {
        return DB::transaction(function () use ($actor, $task, $reason, $channel, $overrideReason): DesignTask {
            [$task, $order] = $this->lockInReview($task);

            $limit = $this->settings->maxCorrectionRounds();
            $round = $task->correction_rounds + 1;
            if ($round > $limit) {
                if (! $actor->can(Permissions::DESIGNS_CORRECTION_OVERRIDE)) {
                    throw ApiException::conflict('correction_limit_reached', "The limit of {$limit} correction round(s) has been reached. Approve the design or ask an administrator to allow another round.");
                }
                if ($overrideReason === null || trim($overrideReason) === '') {
                    throw new ApiException(422, 'validation_failed', 'Give a reason for exceeding the correction limit.', ['override_reason' => ['Give a reason for exceeding the correction limit.']]);
                }
            }

            $review = $this->review($task, $actor, ReviewDecision::ChangesRequested, $channel, $reason);
            DesignCorrectionRequest::query()->create([
                'design_task_id' => $task->id, 'design_review_id' => $review->id, 'description' => $reason,
                'round_no' => $round, 'requested_by' => $actor->id, 'override_reason' => $round > $limit ? $overrideReason : null,
            ]);

            $task->forceFill(['status' => DesignTaskStatus::ChangesRequested, 'correction_rounds' => $round, 'version' => $task->version + 1])->save();
            $this->transition->handle($order, OrderStatus::DesignInProgress, $actor, "Design correction round {$round}: {$reason}");
            $this->audit->record('designs.correction_requested', $task, ['after' => ['round' => $round, 'limit' => $limit, 'reason' => $reason, 'override_reason' => $overrideReason]], actor: $actor, branchId: $order->branch_id);
            $this->notify->correctionRequested($task, $order, $round, $actor);

            return $task;
        });
    }

    public function approve(User $actor, DesignTask $task, ApprovalChannel $channel, ?string $comment): DesignTask
    {
        return DB::transaction(function () use ($actor, $task, $channel, $comment): DesignTask {
            [$task, $order] = $this->lockInReview($task);

            $this->review($task, $actor, ReviewDecision::Approved, $channel, $comment);
            $task->forceFill(['status' => DesignTaskStatus::Approved, 'approved_version_id' => $task->getAttribute('current_version_id'), 'version' => $task->version + 1])->save();
            $this->transition->handle($order, OrderStatus::DesignApproved, $actor, 'Final design approved by the Page Owner.');
            $this->audit->record('designs.approved', $task, ['after' => ['approved_version_id' => $task->getAttribute('approved_version_id'), 'channel' => $channel->value]], actor: $actor, branchId: $order->branch_id);
            $this->notify->approved($task, $order, $actor);

            return $task;
        });
    }

    /** @return array{0: DesignTask, 1: Order} */
    private function lockInReview(DesignTask $task): array
    {
        $task = DesignTask::query()->lockForUpdate()->findOrFail($task->id);
        $order = Order::query()->lockForUpdate()->findOrFail($task->order_id);
        if ($task->status !== DesignTaskStatus::InReview || $task->getAttribute('current_version_id') === null) {
            throw ApiException::conflict('invalid_state', 'This design is not waiting for review.');
        }

        return [$task, $order];
    }

    private function review(DesignTask $task, User $actor, ReviewDecision $decision, ApprovalChannel $channel, ?string $comment): DesignReview
    {
        $review = new DesignReview(['design_version_id' => $task->getAttribute('current_version_id'), 'decision' => $decision, 'channel' => $channel, 'customer_comment' => $comment, 'decided_at' => now()]);
        $review->forceFill(['recorded_by' => $actor->id])->save();

        return $review;
    }
}
