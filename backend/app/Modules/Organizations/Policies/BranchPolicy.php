<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Organizations\Models\Branch;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

final class BranchPolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::BRANCHES_VIEW);
    }

    public function view(User $user, Branch $branch): Response
    {
        return $this->allowScoped($user, $branch, Permissions::BRANCHES_VIEW);
    }

    /** A new branch is outside everyone's assignments, so creating one needs all-branch scope. */
    public function create(User $user): bool
    {
        return $user->can(Permissions::BRANCHES_MANAGE) && $user->hasAllBranchAccess();
    }

    public function update(User $user, Branch $branch): Response
    {
        return $this->allowScoped($user, $branch, Permissions::BRANCHES_MANAGE);
    }
}
