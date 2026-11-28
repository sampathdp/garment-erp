<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings the database in line with the permission catalogue (Permissions) and the system role
 * definitions (Roles). Used by PermissionSeeder and, automatically, by the first web request after
 * a deploy that changed the catalogue (ensureCurrent) — shared hosting deploys over FTP, so nobody
 * has to remember to run the seeder for a new permission to work.
 *
 *  - Missing permissions are created; permissions no longer in the catalogue are deleted (no code
 *    checks them, so they grant nothing).
 *  - Missing system roles are created with their default bundle.
 *  - Locked roles (super_admin) always hold exactly their definition (every permission).
 *  - Existing roles keep the permissions administrators gave them; a permission that is NEW in
 *    this run is granted to every system role whose definition contains it. $resetRoles restores
 *    every definition instead (RESET_ROLE_PERMISSIONS=true).
 */
final class SyncPermissionCatalogue
{
    private const LOCK_KEY = 'identity:permission-catalogue-sync';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Changes whenever a permission or a role definition changes. */
    public static function fingerprint(): string
    {
        return md5(json_encode([Permissions::all(), Roles::definitions()], JSON_THROW_ON_ERROR));
    }

    public static function markerPath(): string
    {
        return storage_path('framework/permission-catalogue.hash');
    }

    /**
     * Cheap check for every web request (a small file, no database query); syncs once per change.
     */
    public function ensureCurrent(): void
    {
        $fingerprint = self::fingerprint();
        if (self::storedFingerprint() === $fingerprint) {
            return;
        }

        Cache::lock(self::LOCK_KEY, 60)->get(function () use ($fingerprint): void {
            if (self::storedFingerprint() !== $fingerprint) {
                $this->handle();
            }
        });
    }

    /** @return list<string> the permissions created by this run */
    public function handle(bool $resetRoles = false): array
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $created = DB::transaction(function () use ($resetRoles): array {
            $catalogue = Permissions::all();
            $existing = Permission::query()->where('guard_name', 'web')->pluck('name')->all();
            $created = array_values(array_diff($catalogue, $existing));
            foreach ($created as $name) {
                Permission::findOrCreate($name, 'web');
            }

            $stale = Permission::query()->where('guard_name', 'web')->whereNotIn('name', $catalogue)->get();
            $removed = $stale->pluck('name')->all();
            $stale->each(fn (Permission $permission) => $permission->delete());

            $granted = [];
            foreach (Roles::definitions() as $name => $definition) {
                /** @var Role|null $role */
                $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
                $newRole = $role === null;

                $role ??= Role::query()->create(['name' => $name, 'guard_name' => 'web']);
                $role->fill(['label' => $definition['label'], 'description' => $definition['description']]);
                $role->forceFill(['is_system' => true])->save();

                if ($newRole || $resetRoles || $role->isLocked()) {
                    $role->syncPermissions($definition['permissions']);
                } elseif (($grant = array_values(array_intersect($created, $definition['permissions']))) !== []) {
                    $role->givePermissionTo($grant);
                    $granted[$name] = $grant;
                }
            }

            if ($created !== [] || $removed !== [] || $resetRoles) {
                $this->audit->record('roles.catalogue_synced', null, ['after' => [
                    'created' => $created, 'removed' => $removed, 'granted' => $granted, 'reset' => $resetRoles,
                ]]);
            }

            return $created;
        });

        $registrar->forgetCachedPermissions();
        @file_put_contents(self::markerPath(), self::fingerprint(), LOCK_EX);

        return $created;
    }

    private static function storedFingerprint(): ?string
    {
        $path = self::markerPath();

        return is_file($path) ? (string) file_get_contents($path) : null;
    }
}
