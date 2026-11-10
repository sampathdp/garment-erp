<?php

declare(strict_types=1);

namespace App\Modules\Design\Policies;

use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Design tasks. Page Owners see every task of their pages; Design Designers see and work on
 * only the tasks assigned to them (designer_id). Scope comes from the order.
 */
final class DesignTaskPolicy extends ScopedPolicy
{
    private const WORKABLE = [DesignTaskStatus::Pending, DesignTaskStatus::InProgress, DesignTaskStatus::ChangesRequested];

    public function viewAny(User $user): bool
    {
        return $user->canAny([Permissions::DESIGNS_VIEW, Permissions::DESIGNS_VIEW_ASSIGNED]);
    }

    public function view(User $user, DesignTask $task): Response
    {
        return $this->decide($this->visible($user, $task), true);
    }

    public function assignDesigner(User $user, DesignTask $task): Response
    {
        return $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_ASSIGN),
            ! in_array($task->status, [DesignTaskStatus::Approved, DesignTaskStatus::Completed], true),
            'An approved design cannot be reassigned.',
        );
    }

    /** Initial and revised submissions (each creates a new version). */
    public function uploadVersion(User $user, DesignTask $task): Response
    {
        return $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_UPLOAD_VERSION) && $this->isAssignee($user, $task),
            in_array($task->status, self::WORKABLE, true),
            'New versions cannot be uploaded while the design is in review or approved.',
        );
    }

    public function respondToCorrection(User $user, DesignTask $task): Response
    {
        return $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_RESPOND_CORRECTION) && $this->isAssignee($user, $task),
            $task->status === DesignTaskStatus::ChangesRequested,
            'There is no open correction request for this design.',
        );
    }

    /** Page Owner review: ask the designer for a correction. */
    public function requestCorrection(User $user, DesignTask $task): Response
    {
        return $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_RECORD_REVIEW),
            $task->status === DesignTaskStatus::InReview,
            'Only a design waiting for review can be sent back for correction.',
        );
    }

    /** Final approval: permission AND owner of the order's page (or company-wide page scope). */
    public function approve(User $user, DesignTask $task): Response
    {
        $response = $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_APPROVE),
            $task->status === DesignTaskStatus::InReview,
            'Only a design in review can be approved.',
        );

        if ($response->allowed() && ! $user->hasAllPageAccess()) {
            $pageId = Order::query()->whereKey($task->getAttribute('order_id'))->value('page_id');
            if (! $user->ownsPage($pageId === null ? null : (int) $pageId)) {
                return Response::deny('Only a Page Owner of this page can approve the final design.', 'not_page_owner');
            }
        }

        return $response;
    }

    /** After approval the assigned designer submits the final PDF/CDR or Drive link. */
    public function submitFinal(User $user, DesignTask $task): Response
    {
        return $this->decide(
            $this->visible($user, $task),
            $user->can(Permissions::DESIGNS_UPLOAD_VERSION) && $this->isAssignee($user, $task),
            $task->status === DesignTaskStatus::Approved,
            'Final files can be submitted only after the design is approved.',
        );
    }

    private function visible(User $user, DesignTask $task): bool
    {
        $assignee = $task->getAttribute('designer_id');

        return $this->canSee($user, $this->orderInScope($user, (int) $task->getAttribute('order_id')),
            Permissions::DESIGNS_VIEW, Permissions::DESIGNS_VIEW_ASSIGNED, $assignee === null ? null : (int) $assignee);
    }

    private function isAssignee(User $user, DesignTask $task): bool
    {
        return (int) $task->getAttribute('designer_id') === $user->id;
    }
}
