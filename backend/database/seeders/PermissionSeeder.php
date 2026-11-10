<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent and safe in production:
 *  - creates missing permissions and removes permissions that are no longer in the catalogue
 *    (no code checks them, so they grant nothing);
 *  - creates missing system roles with their default bundle. Existing system roles keep the
 *    permissions administrators gave them (set RESET_ROLE_PERMISSIONS=true to restore
 *    the defaults), except super_admin, which always holds every permission.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $reset = filter_var(env('RESET_ROLE_PERMISSIONS', false), FILTER_VALIDATE_BOOL);

        DB::transaction(function () use ($reset): void {
            $catalogue = Permissions::all();

            foreach ($catalogue as $name) {
                Permission::findOrCreate($name, 'web');
            }

            Permission::query()->where('guard_name', 'web')->whereNotIn('name', $catalogue)->get()
                ->each(fn (Permission $stale) => $stale->delete());

            foreach (Roles::definitions() as $name => $definition) {
                /** @var Role|null $role */
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                $created = $role === null;

                $role ??= Role::query()->create(['name' => $name, 'guard_name' => 'web']);
                $role->fill(['label' => $definition['label'], 'description' => $definition['description']]);
                $role->forceFill(['is_system' => true])->save();

                if ($created || $reset || $role->isLocked()) {
                    $role->syncPermissions($definition['permissions']);
                }
            }
        });

        $registrar->forgetCachedPermissions();
    }
}
