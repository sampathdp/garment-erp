<?php

declare(strict_types=1);

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use Database\Seeders\PermissionSeeder;
use Spatie\Permission\Models\Permission;

it('seeds every catalogued permission and the nine initial roles idempotently', function () {
    $this->seed(PermissionSeeder::class);
    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->count())->toBe(count(Permissions::all()))
        ->and(Role::query()->where('is_system', true)->pluck('name')->sort()->values()->all())
        ->toBe(collect(array_keys(Roles::definitions()))->sort()->values()->all())
        ->and(Role::findByName(Roles::SUPER_ADMIN)->permissions)->toHaveCount(count(Permissions::all()))
        ->and(Role::findByName(Roles::PAGE_OWNER)->label)->toBe('Page Owner');
});

it('gives each initial role the documented responsibilities', function () {
    $this->seed(PermissionSeeder::class);
    $has = fn (string $role, string $permission) => Role::findByName($role)->hasPermissionTo($permission);

    // Page Owner: own page's orders, customers, design instructions, garment lists, approvals; page-scoped.
    foreach ([Permissions::ORDERS_VIEW, Permissions::CUSTOMERS_MANAGE, Permissions::DESIGNS_INSTRUCT, Permissions::GARMENT_LISTS_MANAGE,
        Permissions::DESIGNS_APPROVE, Permissions::BULK_APPROVE, Permissions::PRINTING_APPROVE_SAMPLE] as $p) {
        expect($has(Roles::PAGE_OWNER, $p))->toBeTrue("page_owner lacks {$p}");
    }
    expect($has(Roles::PAGE_OWNER, Permissions::SCOPE_ALL_PAGES))->toBeFalse()
        ->and($has(Roles::PAGE_OWNER, Permissions::PAYMENTS_VERIFY))->toBeFalse();

    // Second Admin verifies payments; cannot record them (separation of duties by role).
    expect($has(Roles::SECOND_ADMIN, Permissions::PAYMENTS_VERIFY))->toBeTrue()
        ->and($has(Roles::SECOND_ADMIN, Permissions::PAYMENTS_RECORD))->toBeFalse()
        ->and($has(Roles::SECOND_ADMIN, Permissions::SCOPE_ALL_PAGES))->toBeFalse();

    // Task roles: assigned records only.
    expect($has(Roles::DESIGN_DESIGNER, Permissions::DESIGNS_VIEW_ASSIGNED))->toBeTrue()
        ->and($has(Roles::DESIGN_DESIGNER, Permissions::DESIGNS_VIEW))->toBeFalse()
        ->and($has(Roles::BULK_DESIGNER, Permissions::BULK_VIEW))->toBeFalse()
        ->and($has(Roles::EXTERNAL_BULK_DESIGNER, Permissions::ORDERS_VIEW))->toBeFalse()
        ->and($has(Roles::CUTTING_STAFF, Permissions::CUTTING_VIEW))->toBeFalse()
        ->and($has(Roles::CUTTING_STAFF, Permissions::CUTTING_UPDATE_CHECKLIST))->toBeTrue();

    // Operation Admin runs operations but holds no financial or approval permissions.
    expect($has(Roles::OPERATION_ADMIN, Permissions::BULK_ASSIGN))->toBeTrue()
        ->and($has(Roles::OPERATION_ADMIN, Permissions::PRINTING_UPLOAD_SAMPLE))->toBeTrue()
        ->and($has(Roles::OPERATION_ADMIN, Permissions::PRINTING_APPROVE_SAMPLE))->toBeFalse()
        ->and($has(Roles::OPERATION_ADMIN, Permissions::PAYMENTS_VERIFY))->toBeFalse();

    // Nobody but Super Admin administers users or roles.
    foreach (array_keys(Roles::definitions()) as $role) {
        if ($role !== Roles::SUPER_ADMIN) {
            expect($has($role, Permissions::USERS_MANAGE) || $has($role, Permissions::ROLES_MANAGE))->toBeFalse("{$role} can administer");
        }
    }
});

it('keeps administrator edits to system roles on re-seed but always restores super_admin', function () {
    $this->seed(PermissionSeeder::class);
    Role::findByName(Roles::PAGE_OWNER)->revokePermissionTo(Permissions::BULK_APPROVE);
    Role::findByName(Roles::SUPER_ADMIN)->revokePermissionTo(Permissions::USERS_MANAGE);

    $this->seed(PermissionSeeder::class);

    expect(Role::findByName(Roles::PAGE_OWNER)->hasPermissionTo(Permissions::BULK_APPROVE))->toBeFalse()
        ->and(Role::findByName(Roles::SUPER_ADMIN)->hasPermissionTo(Permissions::USERS_MANAGE))->toBeTrue();
});

it('removes permissions that are no longer in the catalogue', function () {
    Permission::findOrCreate('legacy.permission', 'web');

    $this->seed(PermissionSeeder::class);

    expect(Permission::query()->where('name', 'legacy.permission')->exists())->toBeFalse();
});

it('only references catalogued permissions in role bundles', function () {
    foreach (Roles::definitions() as $role => $definition) {
        expect(array_diff($definition['permissions'], Permissions::all()))->toBe([], "Role {$role} references unknown permissions");
    }
});

it('passes the security check with local test configuration', function () {
    $this->artisan('app:security-check')->assertSuccessful();
});

it('fails the security check when debug is enabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true]);

    $this->artisan('app:security-check --env-only')->assertFailed();
});
