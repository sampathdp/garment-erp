<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use Illuminate\Database\Seeder;

/**
 * Idempotent and safe in production (SyncPermissionCatalogue):
 *  - creates missing permissions and removes permissions that are no longer in the catalogue;
 *  - creates missing system roles with their default bundle;
 *  - grants permissions that are new in this run to the system roles that define them. Existing
 *    roles otherwise keep the permissions administrators gave them (set RESET_ROLE_PERMISSIONS=true
 *    to restore the defaults), except super_admin, which always holds every permission.
 *
 * The same sync also runs by itself on the first web request after a deploy that changed the
 * catalogue, so running this seeder after a deploy is optional.
 */
class PermissionSeeder extends Seeder
{
    public function run(SyncPermissionCatalogue $sync): void
    {
        $sync->handle(filter_var(env('RESET_ROLE_PERMISSIONS', false), FILTER_VALIDATE_BOOL));
    }
}
