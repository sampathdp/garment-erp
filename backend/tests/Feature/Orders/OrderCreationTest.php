<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => intakeWorld($this));

it('creates and submits a normal order with a new customer, calculating totals and the advance on the server', function () {
    Notification::fake();

    $response = $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['delivery_fee_minor' => 50000, 'discount_minor' => 20000]))
        ->assertCreated()
        ->assertJsonPath('data.status', ['value' => 'pending_payment_verification', 'label' => 'Pending Payment Verification'])
        ->assertJsonPath('data.order_type.name', 'Single Order')
        ->assertJsonPath('data.product_category.name', 'Jersey')
        ->assertJsonPath('data.gross_quantity', 10)
        ->assertJsonPath('data.due_date', '2026-11-30')
        ->assertJsonPath('data.customer.phone', '+94771234567')
        ->assertJsonPath('data.customer.whatsapp', '+94771234567')
        ->assertJsonPath('data.pricing.subtotal_minor', 1000000)
        ->assertJsonPath('data.pricing.total_minor', 1030000) // 10,000 − 200 + 500
        ->assertJsonPath('data.advance.percent', 30)
        ->assertJsonPath('data.advance.required_minor', 309000)
        ->assertJsonPath('data.advance.shortfall_minor', 309000)
        ->assertJsonPath('data.production_eligible', false)
        ->assertJsonPath('data.urgent.is_urgent', false)
        ->assertJsonPath('data.approval_status', 'Not required')
        ->assertJsonPath('data.abilities.record_payment', true);

    expect($response->json('data.order_no'))->toMatch('/^CMB-JRSY-26-\d{5}$/');

    $order = Order::query()->findOrFail($response->json('data.id'));
    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->first()->page_id)->toBe($this->pageA->id)
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->pluck('to_status')->map->value->all())->toBe(['draft', 'pending_payment_verification'])
        ->and(AuditLog::query()->where('action', 'orders.created')->exists())->toBeTrue();

    Notification::assertSentTo($this->second, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'order.created');
    Notification::assertNotSentTo($this->owner, WorkflowNotification::class); // the creator
    Notification::assertNotSentTo($this->secondB, WorkflowNotification::class); // other page
});

it('saves a draft without submitting, and submits it later with the current version', function () {
    $order = createOrder($this, ['submit' => false]);
    expect($order->status)->toBe(OrderStatus::Draft);

    $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/submit", ['version' => 99])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/submit", ['version' => $order->version])
        ->assertOk()->assertJsonPath('data.status.value', 'pending_payment_verification');
    $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/submit", ['version' => $order->fresh()->version])->assertStatus(409);
});

it('refuses to submit an order without a price', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['unit_price_minor' => 0]))
        ->assertStatus(422)->assertJsonValidationErrors('unit_price_minor');
    expect(Order::query()->count())->toBe(0); // rolled back with its job number
});

it('validates the order form', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', [])
        ->assertStatus(422)->assertJsonValidationErrors(['branch_id', 'page_id', 'order_type_id', 'due_date', 'product_type_id', 'gross_quantity', 'customer']);

    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, [
        'customer' => ['name' => '', 'phone' => '12345', 'whatsapp' => 'abc'], 'gross_quantity' => 0, 'due_date' => '30/11/2026',
    ]))->assertStatus(422)->assertJsonValidationErrors(['customer.name', 'customer.phone', 'customer.whatsapp', 'gross_quantity', 'due_date']);
});

it('rejects inactive order types and product categories', function () {
    $this->single->update(['is_active' => false]);
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this))->assertStatus(422)->assertJsonValidationErrors('order_type_id');
});

it('reuses an existing customer when name and phone match, without overwriting their details', function () {
    $first = createOrder($this);
    $second = createOrder($this, ['customer' => ['name' => '  nimal  PERERA ', 'address' => 'Somewhere else', 'other_phone' => '0112223334']]);

    expect($second->customer_id)->toBe($first->customer_id)
        ->and(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->first()->address_line1)->toBe('12 Galle Road, Colombo 03') // not overwritten
        ->and(Customer::query()->first()->alt_phone_e164)->toBe('+94112223334');               // empty field filled
});

it('does not merge a different person who shares a phone number', function () {
    createOrder($this);

    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['customer' => ['name' => 'Kamala Perera']]))
        ->assertStatus(422)->assertJsonPath('code', 'possible_duplicate_customer')->assertJsonValidationErrors('customer.phone');

    $order = createOrder($this, ['customer' => ['name' => 'Kamala Perera', 'confirm_distinct' => true]]);
    expect(Customer::query()->count())->toBe(2)->and($order->customer->name)->toBe('Kamala Perera');
});

it('creates an order for a selected existing customer of the same page only', function () {
    $mine = createOrder($this)->customer;
    $theirs = createOrder($this, ['page_id' => $this->pageB->id], $this->ownerB)->customer;

    $payload = orderPayload($this, ['customer_id' => $mine->id]);
    unset($payload['customer']);
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', $payload)->assertCreated()->assertJsonPath('data.customer.id', $mine->id);

    $payload['customer_id'] = $theirs->id;
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', $payload)->assertStatus(422)->assertJsonValidationErrors('customer_id');
});

it('requires authentication, the create permission and an assigned page and branch', function () {
    $this->fromFrontend()->postJson('/api/v1/orders', orderPayload($this))->assertUnauthorized();
    $this->signedInAs($this->second)->postJson('/api/v1/orders', orderPayload($this))->assertForbidden();
    $this->signedInAs($this->ownerB)->postJson('/api/v1/orders', orderPayload($this))->assertStatus(422)->assertJsonValidationErrors('page_id');

    $branchLimited = $this->userWith([Permissions::ORDERS_CREATE, Permissions::ORDERS_VIEW, Permissions::SCOPE_ALL_PAGES], []);
    $this->signedInAs($branchLimited)->postJson('/api/v1/orders', orderPayload($this))->assertStatus(422)->assertJsonValidationErrors('branch_id');
});

it('never accepts client-generated job numbers, statuses or totals', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, [
        'order_no' => 'CMB-JRSY-26-00001', 'status' => 'confirmed', 'total_minor' => 1, 'advance_required_minor' => 0, 'is_urgent' => false, 'urgent_status' => 'approved',
    ]))->assertStatus(422)->assertJsonValidationErrors(['order_no', 'status', 'total_minor', 'advance_required_minor', 'is_urgent', 'urgent_status']);
});

it('lists orders by screen group, status and search', function () {
    $pending = createOrder($this);
    $draft = createOrder($this, ['submit' => false, 'customer' => ['name' => 'Sunil Silva', 'phone' => '0779999999', 'whatsapp' => '0779999999']]);
    $urgent = createOrder($this, ['due_date' => '2026-11-12']);

    $ids = fn (string $query) => collect($this->signedInAs($this->owner)->getJson("/api/v1/orders?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('filter[group]=intake'))->toBe(collect([$pending->id, $draft->id])->sort()->values()->all())
        ->and($ids('filter[group]=urgent'))->toBe([$urgent->id])
        ->and($ids('filter[group]=approved'))->toBe([])
        ->and($ids('filter[status]=draft'))->toBe([$draft->id])
        ->and($ids('filter[search]=Sunil'))->toBe([$draft->id])
        ->and($ids('filter[search]=0779999999'))->toBe([$draft->id])
        ->and($ids('filter[search]='.$urgent->order_no))->toBe([$urgent->id]);

    $this->signedInAs($this->owner)->getJson('/api/v1/orders?filter[status]=bogus')->assertStatus(422);
    $this->signedInAs($this->owner)->getJson('/api/v1/orders?sort=total_minor')->assertStatus(422);
});

it('lists orders without N+1 queries', function () {
    foreach (range(1, 5) as $i) {
        createOrder($this, ['customer' => ['name' => "Customer {$i}", 'phone' => "077100000{$i}", 'whatsapp' => "077100000{$i}"]]);
    }

    $this->signedInAs($this->owner);
    DB::enableQueryLog();
    $this->getJson('/api/v1/orders?per_page=25')->assertOk()->assertJsonCount(5, 'data');
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThan(25);
});
