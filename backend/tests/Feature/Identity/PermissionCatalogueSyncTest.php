<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->marker = SyncPermissionCatalogue::markerPath();
});

afterEach(function () {
    @unlink($this->marker);
});

function roleNamed(string $name): Role
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return Role::query()->where('name', $name)->firstOrFail()->load('permissions');
}

/** Simulates the database of a deploy made before $permission existed. */
function forgetPermission(string $permission): void
{
    Permission::query()->where('name', $permission)->firstOrFail()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('grants a new permission to Super Admin and to the system roles that define it, keeping admin edits', function () {
    forgetPermission(Permissions::PRODUCTION_PLAN_VIEW);
    roleNamed(Roles::PAGE_OWNER)->revokePermissionTo(Permissions::CUSTOMERS_MANAGE); // an administrator's edit
    roleNamed(Roles::OPERATION_ADMIN)->givePermissionTo(Permissions::ORDERS_CREATE);  // another one

    $created = app(SyncPermissionCatalogue::class)->handle();

    $owner = roleNamed(Roles::PAGE_OWNER);
    expect($created)->toBe([Permissions::PRODUCTION_PLAN_VIEW])
        ->and($owner->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeTrue()
        ->and($owner->hasPermissionTo(Permissions::CUSTOMERS_MANAGE))->toBeFalse()
        ->and(roleNamed(Roles::OPERATION_ADMIN)->hasPermissionTo(Permissions::ORDERS_CREATE))->toBeTrue()
        ->and(roleNamed(Roles::OPERATION_ADMIN)->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeFalse()
        ->and(roleNamed(Roles::SUPER_ADMIN)->permissions->pluck('name')->sort()->values()->all())->toBe(collect(Permissions::all())->sort()->values()->all());
    $this->assertDatabaseHas('audit_logs', ['action' => 'roles.catalogue_synced']);
});

it('does not re-grant permissions that already existed and were removed by an administrator', function () {
    roleNamed(Roles::PAGE_OWNER)->revokePermissionTo(Permissions::PRODUCTION_PLAN_VIEW);

    expect(app(SyncPermissionCatalogue::class)->handle())->toBe([])
        ->and(roleNamed(Roles::PAGE_OWNER)->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeFalse();
});

it('restores every definition with RESET_ROLE_PERMISSIONS', function () {
    roleNamed(Roles::PAGE_OWNER)->revokePermissionTo(Permissions::PRODUCTION_PLAN_VIEW);

    app(SyncPermissionCatalogue::class)->handle(resetRoles: true);

    expect(roleNamed(Roles::PAGE_OWNER)->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeTrue();
});

it('syncs once per catalogue change on web requests (marker file), without touching the database otherwise', function () {
    $sync = app(SyncPermissionCatalogue::class);
    expect(file_get_contents($this->marker))->toBe(SyncPermissionCatalogue::fingerprint()); // written by the seeder

    forgetPermission(Permissions::PRODUCTION_PLAN_VIEW);
    $sync->ensureCurrent(); // catalogue unchanged since the last sync: nothing to do
    expect(Permission::query()->where('name', Permissions::PRODUCTION_PLAN_VIEW)->exists())->toBeFalse();

    file_put_contents($this->marker, 'an older catalogue'); // what a deploy with new permissions looks like
    $sync->ensureCurrent();
    expect(roleNamed(Roles::SUPER_ADMIN)->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeTrue()
        ->and(roleNamed(Roles::PAGE_OWNER)->hasPermissionTo(Permissions::PRODUCTION_PLAN_VIEW))->toBeTrue()
        ->and(file_get_contents($this->marker))->toBe(SyncPermissionCatalogue::fingerprint());
});
