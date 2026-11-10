<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccessGuard;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Auth\Access\Response;

/**
 * User administration. Order of checks: scope (404) → permission (403) → privilege (403) → state (409).
 */
final class UserPolicy
{
    public function __construct(private readonly AccessGuard $guard) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can(Permissions::USERS_VIEW);
    }

    public function view(User $actor, User $target): Response
    {
        if (! $target->isVisibleTo($actor)) {
            return Response::denyAsNotFound();
        }

        return $actor->can(Permissions::USERS_VIEW) ? Response::allow() : Response::deny();
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permissions::USERS_MANAGE);
    }

    public function update(User $actor, User $target): Response
    {
        if (! $target->isVisibleTo($actor)) {
            return Response::denyAsNotFound();
        }

        if (! $actor->can(Permissions::USERS_MANAGE)) {
            return Response::deny();
        }

        if (! $this->guard->canManage($actor, $target)) {
            return Response::deny('This user has permissions you do not hold.', 'privilege_escalation');
        }

        return Response::allow();
    }

    /** Status, roles, assignments and password resets: never on your own account. */
    public function administer(User $actor, User $target): Response
    {
        $response = $this->update($actor, $target);
        if ($response->denied()) {
            return $response;
        }

        return $actor->is($target)
            ? Response::denyWithStatus(409, 'You cannot change your own status, roles, assignments or password here.', 'own_account')
            : Response::allow();
    }

    public function viewActivity(User $actor, User $target): Response
    {
        if (! $target->isVisibleTo($actor)) {
            return Response::denyAsNotFound();
        }

        return $actor->can(Permissions::USERS_VIEW) && $actor->can(Permissions::AUDIT_VIEW) ? Response::allow() : Response::deny();
    }
}
