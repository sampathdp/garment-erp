<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Branch;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->admin = $this->userWithRole(Roles::SUPER_ADMIN);
});

it('lists roles with permissions and user counts, and the grouped permission catalogue', function () {
    $this->signedInAs($this->admin)->getJson('/api/v1/roles')
        ->assertOk()
        ->assertJsonCount(count(Roles::definitions()), 'data')
        ->assertJsonFragment(['name' => Roles::SUPER_ADMIN, 'is_locked' => true, 'users_count' => 1]);

    $groups = $this->signedInAs($this->admin)->getJson('/api/v1/permissions')->assertOk()->json('data');
    expect(collect($groups)->flatMap(fn ($g) => array_column($g['permissions'], 'name'))->sort()->values()->all())
        ->toBe(collect(Permissions::all())->sort()->values()->all());
});

it('requires authentication and roles permissions', function () {
    $this->fromFrontend()->getJson('/api/v1/roles')->assertUnauthorized();

    $staff = $this->userWithRole(Roles::OPERATION_ADMIN);
    $this->signedInAs($staff)->getJson('/api/v1/roles')->assertForbidden();
    $this->signedInAs($staff)->postJson('/api/v1/roles', ['name' => 'x_role', 'label' => 'X', 'permissions' => []])->assertForbidden();
});

it('creates an additional role and edits its permissions', function () {
    $id = $this->signedInAs($this->admin)->postJson('/api/v1/roles', [
        'name' => 'qc_inspector', 'label' => 'QC Inspector', 'description' => 'Inspects garments', 'permissions' => [Permissions::QC_VIEW, Permissions::QC_INSPECT],
    ])->assertCreated()->assertJsonPath('data.is_system', false)->json('data.id');

    $this->signedInAs($this->admin)->patchJson("/api/v1/roles/{$id}", ['permissions' => [Permissions::QC_VIEW]])
        ->assertOk()->assertJsonPath('data.permissions', [Permissions::QC_VIEW]);

    expect(AuditLog::query()->whereIn('action', ['roles.created', 'roles.updated'])->count())->toBe(2);
});

it('validates role input', function () {
    $this->signedInAs($this->admin)->postJson('/api/v1/roles', ['name' => 'Bad Name!', 'label' => '', 'permissions' => ['not.a.permission']])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'label', 'permissions.0']);
    $this->signedInAs($this->admin)->postJson('/api/v1/roles', ['name' => Roles::PAGE_OWNER, 'label' => 'Dup', 'permissions' => []])
        ->assertStatus(422)->assertJsonValidationErrors('name');
});

it('edits system role permissions, but never renames them', function () {
    $role = Role::findByName(Roles::CUTTING_STAFF);

    $this->signedInAs($this->admin)->patchJson("/api/v1/roles/{$role->id}", ['name' => 'renamed'])->assertStatus(422)->assertJsonValidationErrors('name');
    $this->signedInAs($this->admin)->patchJson("/api/v1/roles/{$role->id}", ['label' => 'Cutting Team', 'permissions' => [...$role->permissions->pluck('name'), Permissions::QC_VIEW]])
        ->assertOk()->assertJsonPath('data.label', 'Cutting Team');

    expect(Role::findByName(Roles::CUTTING_STAFF)->hasPermissionTo(Permissions::QC_VIEW))->toBeTrue();
});

it('locks the super admin role', function () {
    $role = Role::findByName(Roles::SUPER_ADMIN);

    $this->signedInAs($this->admin)->patchJson("/api/v1/roles/{$role->id}", ['permissions' => []])->assertStatus(409)->assertJsonPath('code', 'role_locked');
    $this->signedInAs($this->admin)->deleteJson("/api/v1/roles/{$role->id}")->assertStatus(409);
});

it('only lets administrators grant or revoke permissions they hold', function () {
    $roleAdmin = $this->userWith([Permissions::ROLES_VIEW, Permissions::ROLES_MANAGE, Permissions::QC_VIEW]);

    $this->signedInAs($roleAdmin)->postJson('/api/v1/roles', ['name' => 'sneaky', 'label' => 'Sneaky', 'permissions' => [Permissions::USERS_MANAGE]])
        ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');

    $role = Role::findByName(Roles::DELIVERY_STAFF);
    $this->signedInAs($roleAdmin)->patchJson("/api/v1/roles/{$role->id}", ['permissions' => []])
        ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');

    $this->signedInAs($roleAdmin)->postJson('/api/v1/roles', ['name' => 'viewer', 'label' => 'Viewer', 'permissions' => [Permissions::QC_VIEW]])->assertCreated();
});

it('deletes only unused custom roles', function () {
    $this->signedInAs($this->admin)->deleteJson('/api/v1/roles/'.Role::findByName(Roles::PAGE_OWNER)->id)->assertStatus(409)->assertJsonPath('code', 'role_system');

    $custom = Role::query()->create(['name' => 'temp_role', 'guard_name' => 'web', 'label' => 'Temp']);
    $holder = $this->userWith([]);
    $holder->assignRole($custom);
    $this->signedInAs($this->admin)->deleteJson("/api/v1/roles/{$custom->id}")->assertStatus(409)->assertJsonPath('code', 'role_in_use');

    $holder->removeRole($custom);
    $this->signedInAs($this->admin)->deleteJson("/api/v1/roles/{$custom->id}")->assertNoContent();
    expect(Role::query()->whereKey($custom->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'roles.deleted')->exists())->toBeTrue();
});

it('applies role permission changes to holders on their next request', function () {
    $branch = Branch::factory()->create();
    $user = $this->userWithRole(Roles::DELIVERY_STAFF, [$branch]);
    $this->signedInAs($user)->getJson('/api/v1/branches')->assertOk();

    $role = Role::findByName(Roles::DELIVERY_STAFF);
    $remaining = $role->permissions->pluck('name')->reject(fn ($p) => $p === Permissions::BRANCHES_VIEW)->values()->all();
    $this->signedInAs($this->admin)->patchJson("/api/v1/roles/{$role->id}", ['permissions' => $remaining])->assertOk();

    $this->signedInAs($user->fresh())->getJson('/api/v1/branches')->assertForbidden();
});
