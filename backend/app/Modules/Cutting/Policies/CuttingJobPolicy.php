<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Policies;

use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Cutting jobs (Phase 07). Order of checks: scope (404) → assignment (404 for cutting.view_assigned
 * users) → permission (403) → state (409). Cutting Staff work only on jobs assigned to them
 * (supervisor_id); Operation Admins (cutting.manage) assign cutters and may record work on any job.
 *
 * The Actions re-check every state condition under the row locks.
 */
final class CuttingJobPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::CUTTING_VIEW) || $user->can(Permissions::CUTTING_VIEW_ASSIGNED);
    }

    public function view(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), true);
    }

    /** Assign or reassign the cutter. */
    public function assign(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::CUTTING_MANAGE), $job->status->isOpen(), 'This cutting job is completed.');
    }

    public function manage(User $user, CuttingJob $job): Response
    {
        return $this->assign($user, $job);
    }

    /** Quantities and the cutting checklist: Operation Admins, or the assigned cutter. */
    public function recordWork(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $this->worksOn($user, $job), $job->status->acceptsWork(), $this->workMessage($job));
    }

    public function updateChecklist(User $user, CuttingJob $job): Response
    {
        return $this->recordWork($user, $job);
    }

    /** Quality checks: qc.inspect, on a job the user may see (assigned cutter or cutting.view). */
    public function recordQualityCheck(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::QC_INSPECT), $job->status->acceptsWork(), $this->workMessage($job));
    }

    /** The client confirmation of the final cutting checklist: cutting.confirm_client AND Page Owner of the page. */
    public function confirmChecklist(User $user, CuttingJob $job): Response
    {
        $response = $this->decide($this->visible($user, $job), $user->can(Permissions::CUTTING_CONFIRM_CLIENT), $job->status->acceptsWork(), $this->workMessage($job));
        if ($response->allowed() && ! $user->hasAllPageAccess()) {
            $pageId = Order::query()->whereKey($job->order_id)->value('page_id');
            if (! $user->ownsPage($pageId === null ? null : (int) $pageId)) {
                return Response::deny('Only a Page Owner of this page can record the client confirmation.', 'not_page_owner');
            }
        }

        return $response;
    }

    public function complete(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::CUTTING_COMPLETE),
            $job->status === CuttingJobStatus::InProgress, $job->status === CuttingJobStatus::Completed ? 'This cutting job is already completed.' : 'Only cutting in progress can be completed.');
    }

    public function reportIssue(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::ISSUES_REPORT), $job->status->isOpen(), 'This cutting job is completed.');
    }

    private function worksOn(User $user, CuttingJob $job): bool
    {
        return $user->can(Permissions::CUTTING_MANAGE)
            || ($user->can(Permissions::CUTTING_UPDATE_CHECKLIST) && $job->supervisor_id !== null && $job->supervisor_id === $user->id);
    }

    private function workMessage(CuttingJob $job): string
    {
        return $job->status === CuttingJobStatus::Completed ? 'This cutting job is completed.' : 'Assign a cutter before recording cutting work.';
    }

    private function visible(User $user, CuttingJob $job): bool
    {
        return $this->canSee($user, $job->isVisibleTo($user), Permissions::CUTTING_VIEW, Permissions::CUTTING_VIEW_ASSIGNED, $job->supervisor_id);
    }
}
