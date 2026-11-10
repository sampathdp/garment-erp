<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\AccessGuard;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates or edits a role. The administrator may only add or remove permissions they hold
 * themselves. Changes apply to every holder of the role on their next request.
 */
final class SaveRole
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccessGuard $guard,
        private readonly PermissionRegistrar $registrar,
    ) {}

    /**
     * @param  array<string, string|null>  $attributes  name / label / description
     * @param  list<string>|null  $permissions  null = leave unchanged
     */
    public function handle(User $actor, ?Role $role, array $attributes, ?array $permissions): Role
    {
        $creating = $role === null;
        $before = $creating ? [] : $role->permissions()->pluck('name')->sort()->values()->all();

        if ($permissions !== null) {
            $permissions = collect($permissions)->unique()->sort()->values()->all();
            $this->guard->assertCanGrantPermissions($actor, [...array_diff($permissions, $before), ...array_diff($before, $permissions)]);
        }

        $role = DB::transaction(function () use ($actor, $role, $creating, $attributes, $permissions, $before): Role {
            $role ??= (new Role(['guard_name' => Role::GUARD]))->forceFill(['is_system' => false]);
            $original = $creating ? [] : $role->only(['name', 'label', 'description']);
            $role->fill($attributes)->save();

            if ($permissions !== null) {
                $role->syncPermissions($permissions);
            }

            $this->audit->record($creating ? 'roles.created' : 'roles.updated', $role, [
                'before' => $original + ($creating ? [] : ['permissions' => $before]),
                'after' => $role->only(['name', 'label', 'description']) + ['permissions' => $permissions ?? $before],
            ], actor: $actor);

            return $role;
        });

        $this->registrar->forgetCachedPermissions();

        return $role;
    }
}
