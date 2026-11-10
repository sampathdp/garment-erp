<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Permissions;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;

beforeEach(function () {
    $this->branchA = Branch::factory()->create(['name' => 'Alpha']);
    $this->branchB = Branch::factory()->create(['name' => 'Bravo']);
    $this->pageA = Page::factory()->create(['name' => 'Alpha Page']);
    $this->pageB = Page::factory()->create(['name' => 'Bravo Page']);
});

it('lists only assigned branches', function () {
    $user = $this->userWith([Permissions::BRANCHES_VIEW], [$this->branchA]);

    $this->signedInAs($user)->getJson('/api/v1/branches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->branchA->id)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonStructure(['data', 'meta' => ['page', 'per_page', 'total', 'last_page'], 'links' => ['next', 'prev']]);
});

it('hides other branches as 404, not 403', function () {
    $user = $this->userWith([Permissions::BRANCHES_VIEW], [$this->branchA]);

    $this->signedInAs($user)->getJson("/api/v1/branches/{$this->branchB->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->signedInAs($user)->getJson("/api/v1/branches/{$this->branchA->id}")->assertOk()->assertJsonPath('data.code', $this->branchA->code);
});

it('returns 403 inside scope when the permission is missing', function () {
    $user = $this->userWith([], [$this->branchA]);

    $this->signedInAs($user)->getJson('/api/v1/branches')->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->signedInAs($user)->getJson("/api/v1/branches/{$this->branchA->id}")->assertForbidden();
});

it('denies by default: no assignments means no data', function () {
    $user = $this->userWith([Permissions::BRANCHES_VIEW]);

    $this->signedInAs($user)->getJson('/api/v1/branches')->assertOk()->assertJsonCount(0, 'data');
});

it('shows everything to users with the all-branches scope permission', function () {
    $user = $this->userWith([Permissions::BRANCHES_VIEW, Permissions::SCOPE_ALL_BRANCHES]);

    $this->signedInAs($user)->getJson('/api/v1/branches')->assertOk()->assertJsonCount(2, 'data');
    $this->signedInAs($user)->getJson("/api/v1/branches/{$this->branchB->id}")->assertOk();
});

it('scopes pages the same way', function () {
    $user = $this->userWith([Permissions::PAGES_VIEW], [], [$this->pageA]);

    $this->signedInAs($user)->getJson('/api/v1/pages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->pageA->id);
    $this->signedInAs($user)->getJson("/api/v1/pages/{$this->pageB->id}")->assertNotFound();

    $all = $this->userWith([Permissions::PAGES_VIEW, Permissions::SCOPE_ALL_PAGES]);
    $this->signedInAs($all)->getJson('/api/v1/pages')->assertOk()->assertJsonCount(2, 'data');
});

it('enforces pagination limits and whitelisted sorting', function () {
    $user = $this->userWith([Permissions::BRANCHES_VIEW, Permissions::SCOPE_ALL_BRANCHES]);

    $this->signedInAs($user)->getJson('/api/v1/branches?per_page=101')->assertStatus(422)->assertJsonValidationErrors('per_page');
    $this->signedInAs($user)->getJson('/api/v1/branches?sort=password')->assertStatus(422)->assertJsonValidationErrors('sort');
    $this->signedInAs($user)->getJson('/api/v1/branches?sort=-name')->assertOk()->assertJsonPath('data.0.name', 'Bravo');
    $this->signedInAs($user)->getJson('/api/v1/branches?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
});

it('requires authentication', function () {
    $this->fromFrontend()->getJson('/api/v1/branches')->assertUnauthorized();
});
