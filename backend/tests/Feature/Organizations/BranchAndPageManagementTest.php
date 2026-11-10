<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use Database\Seeders\PermissionSeeder;

beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    Organization::factory()->create();
    $this->admin = $this->userWithRole(Roles::SUPER_ADMIN);
});

function pagePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'jrsy2', 'invoice_prefix' => 'jr2', 'name' => 'Jersey World', 'platform' => 'facebook',
        'invoice_header_name' => 'Jersey World', 'contact_email' => 'hello@example.com', 'contact_phone' => '0112345678',
        'address' => '1 Main Street, Colombo', 'default_advance_percent' => 40, 'default_delivery_fee_minor' => 35000,
    ], $overrides);
}

describe('branches', function () {
    it('creates and updates branches, audited, with immutable codes', function () {
        $id = $this->signedInAs($this->admin)->postJson('/api/v1/branches', ['code' => 'gal', 'name' => 'Galle', 'phone' => '0912222333'])
            ->assertCreated()->assertJsonPath('data.code', 'GAL')->assertJsonPath('data.phone', '+94912222333')->json('data.id');

        $this->signedInAs($this->admin)->patchJson("/api/v1/branches/{$id}", ['name' => 'Galle Fort', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->signedInAs($this->admin)->patchJson("/api/v1/branches/{$id}", ['code' => 'NEW'])->assertStatus(422)->assertJsonValidationErrors('code');

        expect(AuditLog::query()->whereIn('action', ['branches.created', 'branches.deactivated'])->count())->toBe(2);
    });

    it('validates branch input', function () {
        Branch::factory()->create(['code' => 'CMB']);

        $this->signedInAs($this->admin)->postJson('/api/v1/branches', ['code' => 'cmb', 'name' => ''])->assertStatus(422)->assertJsonValidationErrors(['code', 'name']);
        $this->signedInAs($this->admin)->postJson('/api/v1/branches', ['code' => 'bad code!', 'name' => 'X', 'phone' => '12'])->assertStatus(422)->assertJsonValidationErrors(['code', 'phone']);
    });

    it('lets branch-scoped managers edit only their branches and never create new ones', function () {
        $mine = Branch::factory()->create();
        $other = Branch::factory()->create();
        $manager = $this->userWith([Permissions::BRANCHES_VIEW, Permissions::BRANCHES_MANAGE], [$mine]);

        $this->signedInAs($manager)->patchJson("/api/v1/branches/{$mine->id}", ['name' => 'Mine'])->assertOk();
        $this->signedInAs($manager)->patchJson("/api/v1/branches/{$other->id}", ['name' => 'Taken'])->assertNotFound();
        $this->signedInAs($manager)->postJson('/api/v1/branches', ['code' => 'NEW', 'name' => 'New'])->assertForbidden();
        expect($other->fresh()->name)->not->toBe('Taken');
    });

    it('forbids viewers from changing branches', function () {
        $branch = Branch::factory()->create();
        $viewer = $this->userWith([Permissions::BRANCHES_VIEW], [$branch]);

        $this->signedInAs($viewer)->patchJson("/api/v1/branches/{$branch->id}", ['name' => 'X'])->assertForbidden();
    });

    it('requires authentication to change branches and pages', function () {
        $branch = Branch::factory()->create();
        $page = Page::factory()->create();

        $this->fromFrontend()->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'X'])->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/v1/branches/{$branch->id}", ['name' => 'X'])->assertUnauthorized();
        $this->fromFrontend()->postJson('/api/v1/pages', [])->assertUnauthorized();
        $this->fromFrontend()->patchJson("/api/v1/pages/{$page->id}", ['name' => 'X'])->assertUnauthorized();
        $this->fromFrontend()->putJson("/api/v1/pages/{$page->id}/members", ['members' => []])->assertUnauthorized();
    });
});

describe('pages', function () {
    it('creates a page with contact details and pricing configuration', function () {
        $this->signedInAs($this->admin)->postJson('/api/v1/pages', pagePayload())
            ->assertCreated()
            ->assertJsonPath('data.code', 'JRSY2')
            ->assertJsonPath('data.invoice_prefix', 'JR2')
            ->assertJsonPath('data.contact_phone', '+94112345678')
            ->assertJsonPath('data.pricing', ['currency' => 'LKR', 'default_advance_percent' => 40, 'default_delivery_fee_minor' => 35000])
            ->assertJsonPath('data.is_active', true);
    });

    it('enforces the 30% minimum advance and immutable codes', function () {
        $this->signedInAs($this->admin)->postJson('/api/v1/pages', pagePayload(['default_advance_percent' => 20]))
            ->assertStatus(422)->assertJsonValidationErrors('default_advance_percent');

        $page = Page::factory()->create();
        $this->signedInAs($this->admin)->patchJson("/api/v1/pages/{$page->id}", ['invoice_prefix' => 'NEW'])->assertStatus(422)->assertJsonValidationErrors('invoice_prefix');
        $this->signedInAs($this->admin)->patchJson("/api/v1/pages/{$page->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    });

    it('hides other pages from page-scoped users, even by ID', function () {
        $mine = Page::factory()->create();
        $other = Page::factory()->create();
        $owner = $this->userWithRole(Roles::PAGE_OWNER, pages: [$mine]);

        $this->signedInAs($owner)->getJson("/api/v1/pages/{$mine->id}")->assertOk()->assertJsonPath('data.abilities.update', false);
        $this->signedInAs($owner)->getJson("/api/v1/pages/{$other->id}")->assertNotFound();
        $this->signedInAs($owner)->getJson("/api/v1/pages/{$other->id}/members")->assertNotFound();
        $this->signedInAs($owner)->patchJson("/api/v1/pages/{$mine->id}", ['name' => 'X'])->assertForbidden();
    });

    it('assigns page owners and members', function () {
        $page = Page::factory()->create();
        $owner = $this->userWithRole(Roles::PAGE_OWNER);
        $second = $this->userWithRole(Roles::SECOND_ADMIN);

        $this->signedInAs($this->admin)->putJson("/api/v1/pages/{$page->id}/members", ['members' => [
            ['user_id' => $owner->id, 'is_owner' => true], ['user_id' => $second->id, 'is_owner' => false],
        ]])->assertOk()->assertJsonCount(2, 'data');

        $this->signedInAs($this->admin)->getJson("/api/v1/pages/{$page->id}")
            ->assertOk()->assertJsonPath('data.owners', [['id' => $owner->id, 'name' => $owner->name]])->assertJsonPath('data.members_count', 2);
        expect($owner->fresh()->canAccessPage($page->id))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'pages.members_changed')->exists())->toBeTrue();

        // Removing a member removes their access.
        $this->signedInAs($this->admin)->putJson("/api/v1/pages/{$page->id}/members", ['members' => [['user_id' => $owner->id, 'is_owner' => true]]])->assertOk();
        expect($second->fresh()->canAccessPage($page->id))->toBeFalse();
    });

    it('rejects inactive owners and members the administrator cannot manage', function () {
        $page = Page::factory()->create();
        $inactive = $this->userWithRole(Roles::PAGE_OWNER);
        $inactive->forceFill(['is_active' => false])->save();
        $otherAdmin = $this->userWithRole(Roles::SUPER_ADMIN);
        $limited = $this->userWith([Permissions::PAGES_VIEW, Permissions::PAGES_MANAGE, Permissions::USERS_VIEW, Permissions::USERS_MANAGE,
            Permissions::SCOPE_ALL_BRANCHES, Permissions::SCOPE_ALL_PAGES]);

        $this->signedInAs($this->admin)->putJson("/api/v1/pages/{$page->id}/members", ['members' => [['user_id' => $inactive->id, 'is_owner' => true]]])
            ->assertStatus(422)->assertJsonValidationErrors('members');
        $this->signedInAs($limited)->putJson("/api/v1/pages/{$page->id}/members", ['members' => [['user_id' => $otherAdmin->id, 'is_owner' => false]]])
            ->assertStatus(422)->assertJsonValidationErrors('members');
    });

    it('requires both page and user administration to manage members', function () {
        $page = Page::factory()->create();
        $pageManager = $this->userWith([Permissions::PAGES_VIEW, Permissions::PAGES_MANAGE, Permissions::SCOPE_ALL_PAGES]);

        $this->signedInAs($pageManager)->putJson("/api/v1/pages/{$page->id}/members", ['members' => []])->assertForbidden();
    });
});
