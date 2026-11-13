<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Notification::fake();
    $this->branchA = Branch::factory()->create();
    $this->branchB = Branch::factory()->create();
    $this->pageA = Page::factory()->create();
    $this->pageB = Page::factory()->create();
    $this->admin = $this->userWithRole(Roles::SUPER_ADMIN);
});

function newUserPayload(array $overrides = []): array
{
    return array_merge(['name' => 'New Staff', 'email' => 'new.staff@example.com', 'phone' => '0771234567', 'password' => 'Sturdy-Pass-2026!', 'password_confirmation' => 'Sturdy-Pass-2026!', 'roles' => [], 'branch_ids' => [], 'page_ids' => []], $overrides);
}

describe('access control', function () {
    it('requires authentication on every user endpoint', function () {
        $id = $this->admin->id;
        foreach ([['get', '/api/v1/users'], ['post', '/api/v1/users'], ['get', "/api/v1/users/{$id}"], ['patch', "/api/v1/users/{$id}"],
            ['put', "/api/v1/users/{$id}/status"], ['put', "/api/v1/users/{$id}/roles"], ['put', "/api/v1/users/{$id}/assignments"],
            ['post', "/api/v1/users/{$id}/password-reset"], ['put', "/api/v1/users/{$id}/password"], ['get', "/api/v1/users/{$id}/activity"]] as [$method, $uri]) {
            $this->fromFrontend()->json($method, $uri)->assertUnauthorized();
        }
    });

    it('forbids users without user permissions (403) before validating input', function () {
        $staff = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]);
        $target = $this->userWithRole(Roles::BULK_DESIGNER, pages: [$this->pageA]);

        $this->signedInAs($staff)->getJson('/api/v1/users')->assertForbidden();
        // No 422: an unauthorized caller learns nothing (for example whether an email exists).
        $this->signedInAs($staff)->postJson('/api/v1/users', ['email' => $this->admin->email])->assertForbidden();
        $this->signedInAs($staff)->putJson("/api/v1/users/{$target->id}/status", ['is_active' => false])->assertForbidden();
        expect($target->fresh()->is_active)->toBeTrue();
    });

    it('hides users outside the administrator\'s branches and pages as 404', function () {
        $scopedAdmin = $this->userWith([Permissions::USERS_VIEW, Permissions::USERS_MANAGE], [$this->branchA]);
        $inside = $this->userWith([], [$this->branchA]);
        $outside = $this->userWith([], [$this->branchB], [$this->pageB]);

        $ids = collect($this->signedInAs($scopedAdmin)->getJson('/api/v1/users?per_page=100')->assertOk()->json('data'))->pluck('id');
        expect($ids)->toContain($inside->id, $scopedAdmin->id)->not->toContain($outside->id, $this->admin->id);

        $this->signedInAs($scopedAdmin)->getJson("/api/v1/users/{$outside->id}")->assertNotFound();
        $this->signedInAs($scopedAdmin)->patchJson("/api/v1/users/{$outside->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->signedInAs($scopedAdmin)->putJson("/api/v1/users/{$outside->id}/status", ['is_active' => false, 'reason' => 'x'])->assertNotFound();
        expect($outside->fresh()->name)->not->toBe('Hacked');
    });

    it('does not let administrators manage users with more privileges than their own', function () {
        $limitedAdmin = $this->userWith([Permissions::USERS_VIEW, Permissions::USERS_MANAGE, Permissions::SCOPE_ALL_BRANCHES, Permissions::SCOPE_ALL_PAGES]);

        $this->signedInAs($limitedAdmin)->patchJson("/api/v1/users/{$this->admin->id}", ['name' => 'Taken over'])
            ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');
        $this->signedInAs($limitedAdmin)->postJson("/api/v1/users/{$this->admin->id}/password-reset")->assertForbidden();
    });

    it('never lets administrators change their own status, roles or assignments', function () {
        foreach ([['put', 'status', ['is_active' => false, 'reason' => 'x']], ['put', 'roles', ['roles' => []]],
            ['put', 'assignments', ['branch_ids' => [], 'page_ids' => []]], ['post', 'password-reset', []]] as [$method, $path, $body]) {
            $this->signedInAs($this->admin)->json($method, "/api/v1/users/{$this->admin->id}/{$path}", $body)
                ->assertStatus(409)->assertJsonPath('code', 'own_account');
        }
        expect($this->admin->fresh()->is_active)->toBeTrue()->and($this->admin->fresh()->hasRole(Roles::SUPER_ADMIN))->toBeTrue();
    });
});

describe('create and update', function () {
    it('creates a user with roles, assignments and the password entered by the administrator, without sending email', function () {
        $response = $this->signedInAs($this->admin)->postJson('/api/v1/users', newUserPayload([
            'email' => 'NEW.Staff@Example.com', 'roles' => [Roles::PAGE_OWNER], 'page_ids' => [$this->pageA->id], 'owner_page_ids' => [$this->pageA->id],
        ]))->assertCreated();

        $response->assertJsonPath('data.email', 'new.staff@example.com')
            ->assertJsonPath('data.phone', '+94771234567')
            ->assertJsonPath('data.roles.0.name', Roles::PAGE_OWNER)
            ->assertJsonPath('data.pages.0.is_owner', true)
            ->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'new.staff@example.com')->sole();
        Notification::assertNothingSentTo($user);
        expect(Hash::check('Sturdy-Pass-2026!', $user->password))->toBeTrue();
        $audit = AuditLog::query()->where('action', 'users.created')->where('subject_id', $user->id)->sole();
        expect(json_encode($audit->getAttributes()))->not->toContain('Sturdy-Pass-2026!');

        // The new user signs in with that password straight away.
        $this->asGuest()->postJson('/api/v1/auth/login', ['email' => 'new.staff@example.com', 'password' => 'Sturdy-Pass-2026!'])->assertOk();
    });

    it('validates input and rejects duplicate emails', function () {
        $this->signedInAs($this->admin)->postJson('/api/v1/users', ['name' => '', 'email' => 'not-an-email', 'roles' => ['no_such_role'], 'branch_ids' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'password', 'roles.0', 'branch_ids.0']);
        $this->signedInAs($this->admin)->postJson('/api/v1/users', newUserPayload(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->signedInAs($this->admin)->postJson('/api/v1/users', newUserPayload(['password_confirmation' => 'Different-Pass-2026']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->signedInAs($this->admin)->postJson('/api/v1/users', newUserPayload(['email' => $this->admin->email]))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->signedInAs($this->admin)->postJson('/api/v1/users', newUserPayload(['page_ids' => [], 'owner_page_ids' => [$this->pageA->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('owner_page_ids.0');
    });

    it('prevents privilege escalation when creating users', function () {
        $limitedAdmin = $this->userWith([Permissions::USERS_VIEW, Permissions::USERS_MANAGE], [$this->branchA]);

        $this->signedInAs($limitedAdmin)->postJson('/api/v1/users', newUserPayload(['roles' => [Roles::SUPER_ADMIN]]))
            ->assertForbidden()->assertJsonPath('code', 'privilege_escalation');
        $this->signedInAs($limitedAdmin)->postJson('/api/v1/users', newUserPayload(['branch_ids' => [$this->branchB->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('branch_ids');

        expect(User::query()->where('email', 'new.staff@example.com')->exists())->toBeFalse();
    });

    it('updates details and ends sessions when the email changes', function () {
        $user = $this->userWithRole(Roles::CUTTING_STAFF, [$this->branchA]);

        $this->signedInAs($this->admin)->patchJson("/api/v1/users/{$user->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.name', 'Renamed');
        expect($user->fresh()->session_version)->toBe(1);

        $this->signedInAs($this->admin)->patchJson("/api/v1/users/{$user->id}", ['email' => 'changed@example.com'])->assertOk();
        expect($user->fresh()->session_version)->toBe(2)
            ->and(AuditLog::query()->where('action', 'users.updated')->where('subject_id', $user->id)->count())->toBe(2);
    });
});

describe('status, roles and assignments', function () {
    it('deactivates an account, ends its sessions and blocks login', function () {
        $user = $this->userWithRole(Roles::CUTTING_STAFF, [$this->branchA]);

        $this->signedInAs($this->admin)->putJson("/api/v1/users/{$user->id}/status", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->signedInAs($this->admin)->putJson("/api/v1/users/{$user->id}/status", ['is_active' => false, 'reason' => 'Left the company'])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $fresh = $user->fresh();
        expect($fresh->session_version)->toBe(2)->and($fresh->deactivated_at)->not->toBeNull();

        // Their existing session is rejected on the next request.
        $this->signedInAs($fresh)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'account_inactive');
        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Password123'])->assertStatus(422);

        $this->signedInAs($this->admin)->putJson("/api/v1/users/{$user->id}/status", ['is_active' => true])->assertOk()->assertJsonPath('data.is_active', true);
        expect(AuditLog::query()->whereIn('action', ['users.deactivated', 'users.activated'])->count())->toBe(2);
    });

    it('applies role changes on the user\'s next request', function () {
        $user = $this->userWithRole(Roles::DELIVERY_STAFF, [$this->branchA]);
        $this->signedInAs($user)->getJson('/api/v1/branches')->assertOk();

        $this->signedInAs($this->admin)->putJson("/api/v1/users/{$user->id}/roles", ['roles' => [Roles::CUTTING_STAFF]])
            ->assertOk()->assertJsonPath('data.roles.0.name', Roles::CUTTING_STAFF);

        $this->signedInAs($user->fresh())->getJson('/api/v1/branches')->assertForbidden();
        $log = AuditLog::query()->where('action', 'users.roles_changed')->sole();
        // toEqual, not toBe: MySQL's JSON type re-orders object keys (shorter keys first).
        expect($log->changes)->toEqual(['before' => [Roles::DELIVERY_STAFF], 'after' => [Roles::CUTTING_STAFF]]);
    });

    it('applies direct permission revocation immediately', function () {
        $user = $this->userWith([Permissions::BRANCHES_VIEW], [$this->branchA]);
        $this->signedInAs($user)->getJson('/api/v1/branches')->assertOk();

        $user->revokePermissionTo(Permissions::BRANCHES_VIEW);

        $this->signedInAs($user->fresh())->getJson('/api/v1/branches')->assertForbidden();
    });

    it('sets assignments but keeps those outside the administrator\'s scope', function () {
        $scopedAdmin = $this->userWith([Permissions::USERS_VIEW, Permissions::USERS_MANAGE, Permissions::SCOPE_ALL_PAGES], [$this->branchA]);
        $user = $this->userWith([], [$this->branchA, $this->branchB]);

        $this->signedInAs($scopedAdmin)->putJson("/api/v1/users/{$user->id}/assignments", ['branch_ids' => [$this->branchB->id], 'page_ids' => []])
            ->assertStatus(422)->assertJsonValidationErrors('branch_ids');

        $this->signedInAs($scopedAdmin)->putJson("/api/v1/users/{$user->id}/assignments", ['branch_ids' => [], 'page_ids' => [$this->pageB->id], 'owner_page_ids' => [$this->pageB->id]])
            ->assertOk()
            ->assertJsonPath('data.branches.0.id', $this->branchB->id)
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonPath('data.pages.0.id', $this->pageB->id)
            ->assertJsonPath('data.pages.0.is_owner', true);

        expect(AuditLog::query()->where('action', 'users.assignments_changed')->count())->toBe(1);
    });

    it('sends an admin password reset link without revealing the password and ends sessions', function () {
        $user = $this->userWithRole(Roles::CUTTING_STAFF, [$this->branchA]);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->signedInAs($this->admin)->postJson("/api/v1/users/{$user->id}/password-reset")->assertNoContent();

        Notification::assertSentTo($user, ResetPasswordNotification::class, fn ($n) => ! $n->invitation);
        expect($user->fresh()->session_version)->toBe(2)
            ->and(AuditLog::query()->where('action', 'users.password_reset_sent')->exists())->toBeTrue();
    });

    it('lets an administrator set a new password directly, without email, ending the user\'s sessions', function () {
        $user = $this->userWithRole(Roles::CUTTING_STAFF, [$this->branchA]);
        $set = fn ($actor, User $target, array $body) => $this->signedInAs($actor)->putJson("/api/v1/users/{$target->id}/password", $body);
        $body = ['password' => 'Brand-New-Pass-77', 'password_confirmation' => 'Brand-New-Pass-77'];

        $set($this->admin, $user, ['password' => 'short1', 'password_confirmation' => 'short1'])->assertStatus(422)->assertJsonValidationErrors('password');
        $set($this->admin, $user, ['password' => 'Brand-New-Pass-77', 'password_confirmation' => 'other'])->assertStatus(422)->assertJsonValidationErrors('password');
        $set($this->admin, $user, $body)->assertNoContent();

        $user->refresh();
        expect(Hash::check('Brand-New-Pass-77', $user->password))->toBeTrue()
            ->and($user->session_version)->toBe(2)
            ->and($user->password_changed_at)->not->toBeNull();
        Notification::assertNothingSentTo($user);
        $audit = AuditLog::query()->where('action', 'users.password_set')->where('subject_id', $user->id)->sole();
        expect(json_encode($audit->getAttributes()))->not->toContain('Brand-New-Pass-77');
        $this->asGuest()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Brand-New-Pass-77'])->assertOk();

        // Same guards as every other administration action.
        $set($this->admin, $this->admin, $body)->assertStatus(409)->assertJsonPath('code', 'own_account');
        $limitedAdmin = $this->userWith([Permissions::USERS_VIEW, Permissions::USERS_MANAGE, Permissions::SCOPE_ALL_BRANCHES, Permissions::SCOPE_ALL_PAGES]);
        $set($limitedAdmin, $this->admin, $body)->assertForbidden();
        $set($this->userWith([Permissions::USERS_VIEW, Permissions::SCOPE_ALL_BRANCHES, Permissions::SCOPE_ALL_PAGES]), $user, $body)->assertForbidden();
        $this->asGuest()->putJson("/api/v1/users/{$user->id}/password", $body)->assertUnauthorized();
    });
});

describe('activity', function () {
    it('shows actions by and on the user to holders of audit.view only', function () {
        $user = $this->userWithRole(Roles::CUTTING_STAFF, [$this->branchA]);
        $this->signedInAs($this->admin)->patchJson("/api/v1/users/{$user->id}", ['name' => 'Renamed'])->assertOk();

        $this->signedInAs($this->admin)->getJson("/api/v1/users/{$user->id}/activity")
            ->assertOk()->assertJsonPath('data.0.action', 'users.updated')->assertJsonPath('data.0.actor.id', $this->admin->id);

        $viewer = $this->userWith([Permissions::USERS_VIEW, Permissions::SCOPE_ALL_BRANCHES, Permissions::SCOPE_ALL_PAGES]);
        $this->signedInAs($viewer)->getJson("/api/v1/users/{$user->id}/activity")->assertForbidden();
    });
});

describe('listing', function () {
    it('filters, sorts and paginates without N+1 queries', function () {
        foreach (range(1, 6) as $i) {
            $this->userWithRole(Roles::BULK_DESIGNER, [$this->branchA], [$this->pageA]);
        }

        DB::enableQueryLog();
        $this->signedInAs($this->admin)->getJson('/api/v1/users?filter[role]='.Roles::BULK_DESIGNER.'&sort=-name&per_page=5')
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 6);
        // Constant number of queries regardless of page size (eager loading).
        expect(count(DB::getQueryLog()))->toBeLessThan(20);
        DB::disableQueryLog();
        $this->signedInAs($this->admin)->getJson('/api/v1/users?sort=password')->assertStatus(422);
        $this->signedInAs($this->admin)->getJson("/api/v1/users?filter[branch_id]={$this->branchB->id}")->assertOk()->assertJsonCount(0, 'data');
    });
});
