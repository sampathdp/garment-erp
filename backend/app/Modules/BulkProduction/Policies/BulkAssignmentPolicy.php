<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Policies;

use App\Modules\BulkProduction\Enums\WorkStatus;
use App\Modules\BulkProduction\Models\BulkAssignment;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Bulk jobs. Operation Admins and Page Owners see every job in scope (bulk.view); internal and
 * external Bulk Designers see and work only on jobs assigned to them (bulk_designer_id).
 */
final class BulkAssignmentPolicy extends ScopedPolicy
{
    private const OPEN = [WorkStatus::Pending, WorkStatus::InProgress];

    public function view(User $user, BulkAssignment $job): Response
    {
        return $this->decide($this->visible($user, $job), true);
    }

    public function enterMeasurements(User $user, BulkAssignment $job): Response
    {
        return $this->assigneeWork($user, $job, Permissions::BULK_MEASURE);
    }

    public function uploadFiles(User $user, BulkAssignment $job): Response
    {
        return $this->assigneeWork($user, $job, Permissions::BULK_UPLOAD_FILES);
    }

    public function completeChecklist(User $user, BulkAssignment $job): Response
    {
        return $this->assigneeWork($user, $job, Permissions::BULK_COMPLETE_CHECKLIST);
    }

    public function reportIssue(User $user, BulkAssignment $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::ISSUES_REPORT), in_array($job->status, self::OPEN, true), 'This bulk job is closed.');
    }

    private function assigneeWork(User $user, BulkAssignment $job, string $permission): Response
    {
        return $this->decide(
            $this->visible($user, $job),
            $user->can($permission) && (int) $job->getAttribute('bulk_designer_id') === $user->id,
            in_array($job->status, self::OPEN, true),
            'This bulk job is closed.',
        );
    }

    private function visible(User $user, BulkAssignment $job): bool
    {
        $assignee = $job->getAttribute('bulk_designer_id');

        return $this->canSee($user, $job->isVisibleTo($user), Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, $assignee === null ? null : (int) $assignee);
    }
}
