<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Roles;

beforeEach(fn () => intakeWorld($this));

it('lists the seeded order types and product categories', function () {
    $this->signedInAs($this->owner)->getJson('/api/v1/order-types')->assertOk()
        ->assertJsonPath('data.items.0.name', 'Single Order')->assertJsonPath('data.items.1.name', 'Bulk Order');
    $names = collect($this->signedInAs($this->owner)->getJson('/api/v1/product-categories')->assertOk()->json('data.items'))->pluck('name')->all();
    expect($names)->toContain('Jersey', 'Arm Cut', 'Short', 'Bottom', 'T-Shirt');
});

it('lets catalogue managers add and deactivate entries, never rename codes', function () {
    $admin = $this->userWithRole(Roles::SUPER_ADMIN);

    $id = $this->signedInAs($admin)->postJson('/api/v1/product-categories', ['code' => 'Hoodie', 'name' => 'Hoodie'])->assertCreated()
        ->assertJsonPath('data.code', 'hoodie')->json('data.id');
    $this->signedInAs($admin)->postJson('/api/v1/product-categories', ['code' => 'hoodie', 'name' => 'Again'])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->signedInAs($admin)->patchJson("/api/v1/product-categories/{$id}", ['code' => 'x'])->assertStatus(422);
    $this->signedInAs($admin)->patchJson("/api/v1/product-categories/{$id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

    $this->signedInAs($admin)->postJson('/api/v1/order-types', ['code' => 'school', 'name' => 'School Order'])->assertCreated();

    $this->signedInAs($this->owner)->postJson('/api/v1/order-types', ['code' => 'x1', 'name' => 'X'])->assertForbidden();
    $this->signedInAs($this->owner)->getJson('/api/v1/product-categories?filter[active]=1')->assertOk()->assertJsonMissing(['name' => 'Hoodie']);
    $this->asGuest()->getJson('/api/v1/order-types')->assertUnauthorized();
});
