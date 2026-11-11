<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Sewing\Enums\SewingJobStatus;
use App\Modules\Sewing\Models\SewingJob;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

/** Sewing (Phase 08, minimal step): production viewers see it, sewing.manage holders run it. */
final class SewingJobPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PRODUCTION_VIEW) || $user->can(Permissions::SEWING_MANAGE);
    }

    public function view(User $user, SewingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), true);
    }

    public function start(User $user, SewingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::SEWING_MANAGE), $job->status === SewingJobStatus::Pending, 'Sewing has already started.');
    }

    public function complete(User $user, SewingJob $job): Response
    {
        return $this->decide($this->visible($user, $job), $user->can(Permissions::SEWING_MANAGE), $job->status === SewingJobStatus::InProgress,
            $job->status === SewingJobStatus::Completed ? 'Sewing is already completed.' : 'Start sewing before completing it.');
    }

    private function visible(User $user, SewingJob $job): bool
    {
        return $job->isVisibleTo($user) && $this->viewAny($user);
    }
}
