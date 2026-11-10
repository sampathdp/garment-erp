<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Policies;

use App\Modules\BulkProduction\Enums\WorkStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Cutting: Cutting Staff work only on jobs assigned to them (supervisor_id). */
final class CuttingJobPolicy extends ScopedPolicy
{
    private const OPEN = [WorkStatus::Pending, WorkStatus::InProgress];

    public function view(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), true);
    }

    public function manage(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::CUTTING_MANAGE), in_array($job->status, self::OPEN, true), 'This cutting job is closed.');
    }

    public function updateChecklist(User $user, CuttingJob $job): Response
    {
        $permitted = $user->can(Permissions::CUTTING_MANAGE)
            || ($user->can(Permissions::CUTTING_UPDATE_CHECKLIST) && (int) $job->getAttribute('supervisor_id') === $user->id);

        return $this->decide($this->visible($user, $job), $permitted, in_array($job->status, self::OPEN, true), 'This cutting job is closed.');
    }

    public function reportIssue(User $user, CuttingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::ISSUES_REPORT), in_array($job->status, self::OPEN, true), 'This cutting job is closed.');
    }

    private function visible(User $user, CuttingJob $job): bool
    {
        $assignee = $job->getAttribute('supervisor_id');

        return $this->canSee($user, $job->isVisibleTo($user), Permissions::CUTTING_VIEW, Permissions::CUTTING_VIEW_ASSIGNED, $assignee === null ? null : (int) $assignee);
    }
}
