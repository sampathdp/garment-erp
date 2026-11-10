<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Auth\Access\Response;

/**
 * Roles are company-wide configuration (no branch scope). Granting rules (an administrator can
 * only grant permissions they hold) are enforced in the actions through AccessGuard.
 */
final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permissions::ROLES_VIEW) || $actor->can(Permissions::ROLES_MANAGE);
    }

    public function view(User $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permissions::ROLES_MANAGE);
    }

    public function update(User $actor, Role $role): Response
    {
        if (! $actor->can(Permissions::ROLES_MANAGE)) {
            return Response::deny();
        }

        return $role->isLocked()
            ? Response::denyWithStatus(409, 'This role is locked and always holds every permission.', 'role_locked')
            : Response::allow();
    }

    public function delete(User $actor, Role $role): Response
    {
        $response = $this->update($actor, $role);
        if ($response->denied()) {
            return $response;
        }

        if ($role->is_system) {
            return Response::denyWithStatus(409, 'System roles cannot be deleted.', 'role_system');
        }

        $inUse = array_key_exists('users_count', $role->getAttributes()) ? (int) $role->getAttribute('users_count') > 0 : $role->users()->exists();

        return $inUse
            ? Response::denyWithStatus(409, 'Remove this role from all users before deleting it.', 'role_in_use')
            : Response::allow();
    }
}
