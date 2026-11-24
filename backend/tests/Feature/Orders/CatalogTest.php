<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Roles;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => intakeWorld($this));

it('lists the seeded product categories', function () {
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

    $this->signedInAs($this->owner)->postJson('/api/v1/product-categories', ['code' => 'x1', 'name' => 'X'])->assertForbidden();
    $this->signedInAs($this->owner)->getJson('/api/v1/product-categories?filter[active]=1')->assertOk()->assertJsonMissing(['name' => 'Hoodie']);
    $this->asGuest()->getJson('/api/v1/product-categories')->assertUnauthorized();
});

it('no longer exposes order types', function () {
    $this->signedInAs($this->owner)->getJson('/api/v1/order-types')->assertNotFound();
    expect(Schema::hasTable('order_types'))->toBeFalse()
        ->and(Schema::hasColumn('orders', 'order_type_id'))->toBeFalse();
});
