<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Privilege-escalation rules for administration (central, used by policies and actions):
 *  - an administrator can only manage users whose permissions are a subset of their own;
 *  - can only grant or revoke roles/permissions they hold themselves;
 *  - can only assign branches and pages inside their own data scope.
 */
final class AccessGuard
{
    public function canManage(User $actor, User $target): bool
    {
        return array_diff($target->permissionNames(), $actor->permissionNames()) === [];
    }

    /**
     * @param  iterable<string>  $permissions
     */
    public function assertCanGrantPermissions(User $actor, iterable $permissions): void
    {
        $missing = array_values(array_diff(collect($permissions)->unique()->all(), $actor->permissionNames()));

        if ($missing !== []) {
            throw ApiException::forbidden('privilege_escalation', 'You cannot grant or revoke permissions you do not hold: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    public function assertCanGrantRoles(User $actor, Collection $roles): void
    {
        $roles->loadMissing('permissions');
        $this->assertCanGrantPermissions($actor, $roles->flatMap(fn (Role $r) => $r->permissions->pluck('name')));
    }

    /**
     * @param  list<int>  $branchIds
     * @param  list<int>  $pageIds
     */
    public function assertScopeAssignable(User $actor, array $branchIds, array $pageIds): void
    {
        $errors = [];
        foreach ($branchIds as $id) {
            if (! $actor->canAccessBranch($id)) {
                $errors['branch_ids'] = ['You can only assign branches you have access to.'];
            }
        }
        foreach ($pageIds as $id) {
            if (! $actor->canAccessPage($id)) {
                $errors['page_ids'] = ['You can only assign pages you have access to.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
