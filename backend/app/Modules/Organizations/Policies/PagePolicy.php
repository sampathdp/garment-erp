<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Organizations\Models\Page;
use App\Support\Authorization\ScopedPolicy;
use Illuminate\Auth\Access\Response;

final class PagePolicy extends ScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::PAGES_VIEW);
    }

    public function view(User $user, Page $page): Response
    {
        return $this->allowScoped($user, $page, Permissions::PAGES_VIEW);
    }

    /** A new page is outside everyone's assignments, so creating one needs all-page scope. */
    public function create(User $user): bool
    {
        return $user->can(Permissions::PAGES_MANAGE) && $user->hasAllPageAccess();
    }

    public function update(User $user, Page $page): Response
    {
        return $this->allowScoped($user, $page, Permissions::PAGES_MANAGE);
    }

    /** Page owners and members change user assignments, so user administration is needed too. */
    public function manageMembers(User $user, Page $page): Response
    {
        $response = $this->allowScoped($user, $page, Permissions::PAGES_MANAGE);

        return $response->allowed() && ! $user->can(Permissions::USERS_MANAGE) ? Response::deny() : $response;
    }
}
