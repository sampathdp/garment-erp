<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Policies;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Bulk jobs (Phase 05). Order of checks: scope (404) → assignment (404) → permission (403) → state (409).
 *
 *  - Operation Admins and Page Owners see every job in scope (bulk.view); Bulk Designers see only
 *    jobs assigned to them (bulk.view_assigned + bulk_designer_id).
 *  - Internal work (measurements, checklist, review submissions, final submission) belongs to the
 *    assigned Bulk Designer. External work is recorded by holders of bulk.manage_external.
 *  - Review decisions and the client checklist confirmation: bulk.approve AND Page Owner of the
 *    order's page (or company-wide page scope).
 *
 * The Actions re-check every state condition under the row lock.
 */
final class BulkJobPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED]);
    }

    public function view(User $user, BulkJob $job): Response
    {
        return $this->decide($this->visible($user, $job), true);
    }

    /**
     * First assignment and reassignment. External assignments are restricted: once a job went to
     * an external producer, only bulk.assignment_override holders can change it.
     */
    public function assign(User $user, BulkJob $job): Response
    {
        if (! $this->visible($user, $job)) {
            return Response::denyAsNotFound();
        }
        if (! $user->can(Permissions::BULK_ASSIGN)) {
            return Response::deny();
        }
        if ($job->isExternal() && ! $user->can(Permissions::BULK_ASSIGNMENT_OVERRIDE)) {
            return Response::deny('External assignments can only be changed by an authorised administrator.', 'external_assignment_restricted');
        }
        if ($job->status->isClosed()) {
            return $this->conflict('A completed bulk job cannot be reassigned.');
        }
        if (in_array($job->status, [BulkJobStatus::InReview, BulkJobStatus::Approved], true) && ! $user->can(Permissions::BULK_ASSIGNMENT_OVERRIDE)) {
            return $this->conflict('The bulk is in review or approved; it cannot be reassigned now.');
        }

        return Response::allow();
    }

    public function enterMeasurements(User $user, BulkJob $job): Response
    {
        return $this->work($user, $job, Permissions::BULK_MEASURE);
    }

    public function updateChecklist(User $user, BulkJob $job): Response
    {
        return $this->work($user, $job, Permissions::BULK_COMPLETE_CHECKLIST);
    }

    /** Internal: the assigned designer submits (or resubmits) the prepared bulk for review. */
    public function submitForReview(User $user, BulkJob $job): Response
    {
        return $this->decide(
            $this->visible($user, $job),
            $job->isInternal() && $user->can(Permissions::BULK_UPLOAD_FILES) && $this->isAssignee($user, $job),
            in_array($job->status, [BulkJobStatus::Assigned, BulkJobStatus::InProgress, BulkJobStatus::ChangesRequested], true),
            'The bulk cannot be submitted for review in its current state.',
        );
    }

    /** Page Owner review of the submitted version (request correction or approve). */
    public function review(User $user, BulkJob $job): Response
    {
        return $this->pageOwnerDecision($user, $job, $job->status === BulkJobStatus::InReview, 'Only a bulk waiting for review can be reviewed.');
    }

    /** The client's confirmation of the final checklist, recorded by the Page Owner (customers do not log in). */
    public function confirmChecklist(User $user, BulkJob $job): Response
    {
        $open = $job->mode !== null && ($job->status->acceptsWork($job->mode) || $job->status === BulkJobStatus::InReview);

        return $this->pageOwnerDecision($user, $job, $open, 'The checklist cannot be confirmed in the current state.');
    }

    /** Internal: final bulk files + WeTransfer link after approval; completes the task. */
    public function submitFinal(User $user, BulkJob $job): Response
    {
        return $this->decide(
            $this->visible($user, $job),
            $job->isInternal() && $user->can(Permissions::BULK_UPLOAD_FILES) && $this->isAssignee($user, $job),
            $job->status === BulkJobStatus::Approved,
            'Final bulk files can be submitted only after the bulk is approved.',
        );
    }

    /** External: the Operation Admin records evidence and completion. */
    public function manageExternal(User $user, BulkJob $job): Response
    {
        return $this->decide(
            $this->visible($user, $job),
            $job->isExternal() && $user->can(Permissions::BULK_MANAGE_EXTERNAL),
            in_array($job->status, [BulkJobStatus::Assigned, BulkJobStatus::InProgress], true),
            'This external bulk job is already closed.',
        );
    }

    /** Administrative correction of measurements after the bulk stage was finalised. */
    public function correctMeasurements(User $user, BulkJob $job): Response
    {
        return $this->decide(
            $this->visible($user, $job),
            $user->can(Permissions::BULK_CORRECT_MEASUREMENTS),
            $job->status === BulkJobStatus::Completed,
            'Measurements are corrected through the normal entry until the bulk is completed.',
        );
    }

    /**
     * Internal jobs: the assigned designer with $internalPermission. External jobs: bulk.manage_external.
     * Frozen while in review and once closed.
     */
    private function work(User $user, BulkJob $job, string $internalPermission): Response
    {
        $permitted = match ($job->mode) {
            ProductionMode::Internal => $user->can($internalPermission) && $this->isAssignee($user, $job),
            ProductionMode::External => $user->can(Permissions::BULK_MANAGE_EXTERNAL),
            null => $user->can($internalPermission) && $this->isAssignee($user, $job) || $user->can(Permissions::BULK_MANAGE_EXTERNAL),
        };

        return $this->decide(
            $this->visible($user, $job),
            $permitted,
            $job->mode !== null && $job->status->acceptsWork($job->mode),
            $job->mode === null ? 'The bulk job has not been assigned yet.' : 'The bulk job does not accept changes in its current state.',
        );
    }

    private function pageOwnerDecision(User $user, BulkJob $job, bool $stateAllows, string $stateMessage): Response
    {
        $response = $this->decide($this->visible($user, $job), $user->can(Permissions::BULK_APPROVE), $stateAllows, $stateMessage);

        if ($response->allowed() && ! $user->hasAllPageAccess()) {
            $pageId = Order::query()->whereKey($job->getAttribute('order_id'))->value('page_id');
            if (! $user->ownsPage($pageId === null ? null : (int) $pageId)) {
                return Response::deny('Only a Page Owner of this page can review the bulk.', 'not_page_owner');
            }
        }

        return $response;
    }

    private function visible(User $user, BulkJob $job): bool
    {
        $assignee = $job->getAttribute('bulk_designer_id');

        return $this->canSee($user, $job->isVisibleTo($user), Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, $assignee === null ? null : (int) $assignee);
    }

    private function isAssignee(User $user, BulkJob $job): bool
    {
        return (int) $job->getAttribute('bulk_designer_id') === $user->id;
    }
}
