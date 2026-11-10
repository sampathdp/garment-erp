<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Models\Customer;
use Tests\TestCase;

beforeEach(fn () => intakeWorld($this));

function customerPayload(TestCase $test, array $overrides = []): array
{
    return array_merge([
        'page_id' => $test->pageA->id, 'branch_id' => $test->branch->id, 'name' => 'Ruwan Jayasinghe',
        'phone' => '077 555 1234', 'whatsapp' => '+94 77 555 1234', 'other_phone' => '011-2345678', 'address' => '5 Temple Road, Kandy',
    ], $overrides);
}

it('creates a customer with normalised phone numbers, a code and the page association', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this))
        ->assertCreated()
        ->assertJsonPath('data.customer_code', 'CUS-000001')
        ->assertJsonPath('data.phone', '+94775551234')
        ->assertJsonPath('data.whatsapp', '+94775551234')
        ->assertJsonPath('data.other_phone', '+94112345678')
        ->assertJsonPath('data.page.id', $this->pageA->id)
        ->assertJsonPath('data.branch.id', $this->branch->id)
        ->assertJsonPath('data.abilities.update', true);

    expect(AuditLog::query()->where('action', 'customers.created')->exists())->toBeTrue();
});

it('validates customer input and the page/branch scope', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this, ['name' => '', 'phone' => '123', 'whatsapp' => '', 'other_phone' => 'x']))
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone', 'whatsapp', 'other_phone']);
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this, ['page_id' => $this->pageB->id]))
        ->assertStatus(422)->assertJsonValidationErrors('page_id');
    $this->signedInAs($this->second)->postJson('/api/v1/customers', customerPayload($this))->assertForbidden();
    $this->asGuest()->postJson('/api/v1/customers', customerPayload($this))->assertUnauthorized();
});

it('prevents duplicates without merging different people', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this))->assertCreated();

    // Same person again.
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this, ['name' => 'ruwan  jayasinghe']))
        ->assertStatus(409)->assertJsonPath('code', 'duplicate_customer');
    // Different person sharing the number: needs confirmation, then a separate record.
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this, ['name' => 'Ruwan Jayasinghe Jr']))
        ->assertStatus(422)->assertJsonPath('code', 'possible_duplicate_customer');
    $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this, ['name' => 'Ruwan Jayasinghe Jr', 'confirm_distinct' => true]))->assertCreated();
    // Another page may have its own record for the same person.
    $this->signedInAs($this->ownerB)->postJson('/api/v1/customers', customerPayload($this, ['page_id' => $this->pageB->id, 'branch_id' => null]))->assertCreated();

    expect(Customer::query()->count())->toBe(3);
});

it('updates, searches and deletes customers', function () {
    $id = $this->signedInAs($this->owner)->postJson('/api/v1/customers', customerPayload($this))->json('data.id');

    $this->signedInAs($this->owner)->patchJson("/api/v1/customers/{$id}", ['address' => 'New address', 'page_id' => $this->pageB->id])->assertStatus(422)->assertJsonValidationErrors('page_id');
    $this->signedInAs($this->owner)->patchJson("/api/v1/customers/{$id}", ['address' => 'New address'])->assertOk()->assertJsonPath('data.address', 'New address');

    foreach (['Ruwan', '0775551234', 'CUS-0000'] as $term) {
        $this->signedInAs($this->owner)->getJson('/api/v1/customers?filter[search]='.urlencode($term))->assertOk()->assertJsonCount(1, 'data');
    }
    $this->signedInAs($this->owner)->getJson('/api/v1/customers?filter[search]=Nobody')->assertOk()->assertJsonCount(0, 'data');

    $this->signedInAs($this->ownerB)->deleteJson("/api/v1/customers/{$id}")->assertNotFound();
    $this->signedInAs($this->owner)->deleteJson("/api/v1/customers/{$id}")->assertNoContent();
    expect(Customer::query()->find($id))->toBeNull()->and(Customer::withTrashed()->find($id))->not->toBeNull();
});

it('never deletes a customer who has orders', function () {
    $order = createOrder($this);
    $this->signedInAs($this->owner)->deleteJson("/api/v1/customers/{$order->customer_id}")->assertStatus(409)->assertJsonPath('code', 'customer_has_orders');
    $this->signedInAs($this->owner)->getJson("/api/v1/customers/{$order->customer_id}")->assertOk()->assertJsonPath('data.orders_count', 1);
    $this->signedInAs($this->owner)->getJson("/api/v1/orders?filter[customer_id]={$order->customer_id}")->assertOk()->assertJsonCount(1, 'data');
});
