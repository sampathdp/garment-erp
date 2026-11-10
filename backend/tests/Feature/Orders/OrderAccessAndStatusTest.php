<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;

beforeEach(function () {
    intakeWorld($this);
    $this->order = createOrder($this);
});

describe('cross-page access', function () {
    it('hides another page\'s order, its payments and its customer, even by ID', function () {
        $id = $this->order->id;

        $this->signedInAs($this->ownerB)->getJson("/api/v1/orders/{$id}")->assertNotFound();
        $this->signedInAs($this->ownerB)->patchJson("/api/v1/orders/{$id}", ['gross_quantity' => 1, 'version' => 1])->assertNotFound();
        $this->signedInAs($this->ownerB)->postJson("/api/v1/orders/{$id}/submit", ['version' => 1])->assertNotFound();
        $this->signedInAs($this->ownerB)->getJson("/api/v1/orders/{$id}/payments")->assertNotFound();
        $this->signedInAs($this->ownerB)->post("/api/v1/orders/{$id}/payments", ['amount_minor' => 1, 'method' => 'cash', 'payment_date' => '2026-11-09'], ['Accept' => 'application/json'])->assertNotFound();
        $this->signedInAs($this->ownerB)->getJson("/api/v1/customers/{$this->order->customer_id}")->assertNotFound();

        $this->signedInAs($this->ownerB)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->ownerB)->getJson('/api/v1/customers')->assertOk()->assertJsonCount(0, 'data');
    });

    it('lets company-wide operations see every page', function () {
        $this->signedInAs($this->ops)->getJson("/api/v1/orders/{$this->order->id}")->assertOk()->assertJsonPath('data.abilities.update', false);
    });

    it('denies users without any assignment', function () {
        $nobody = $this->userWith([Permissions::ORDERS_VIEW]);
        $this->signedInAs($nobody)->getJson("/api/v1/orders/{$this->order->id}")->assertNotFound();
    });
});

describe('direct status changes', function () {
    it('rejects status and other server-owned fields on update', function () {
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$this->order->id}", ['status' => 'confirmed', 'version' => $this->order->version])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$this->order->id}", ['urgent_status' => 'approved', 'payment_status' => 'paid', 'verified_paid_minor' => 999999, 'order_no' => 'X', 'version' => $this->order->version])
            ->assertStatus(422)->assertJsonValidationErrors(['urgent_status', 'payment_status', 'verified_paid_minor', 'order_no']);

        expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPaymentVerification);
    });

    it('has no endpoint that sets a status', function () {
        $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$this->order->id}/transitions", ['to' => 'confirmed'])->assertNotFound();
        $this->signedInAs($this->owner)->putJson("/api/v1/orders/{$this->order->id}", ['status' => 'confirmed'])->assertStatus(405);
    });

    it('rejects payment fields that only verification may set', function () {
        $this->signedInAs($this->owner)->post("/api/v1/orders/{$this->order->id}/payments", [
            'amount_minor' => 300000, 'method' => 'cash', 'payment_date' => '2026-11-09', 'status' => 'verified', 'verified_by' => $this->second->id,
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors(['status', 'verified_by']);

        expect($this->order->fresh()->verified_paid_minor)->toBe(0);
    });

    it('refuses invalid workflow actions with 409', function () {
        $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$this->order->id}/submit", ['version' => $this->order->version])->assertStatus(409);

        verifyPayment($this, recordPayment($this, $this->order, 300000));
        $approved = $this->order->fresh();
        expect($approved->status)->toBe(OrderStatus::Confirmed);

        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$approved->id}", ['gross_quantity' => 50, 'version' => $approved->version])->assertStatus(409);
    });

    it('detects concurrent edits with the version', function () {
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$this->order->id}", ['gross_quantity' => 20, 'version' => $this->order->version])
            ->assertOk()->assertJsonPath('data.pricing.total_minor', 2000000)->assertJsonPath('data.advance.required_minor', 600000);
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$this->order->id}", ['gross_quantity' => 30, 'version' => $this->order->version])
            ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
    });

    it('needs the update permission', function () {
        $this->signedInAs($this->second)->patchJson("/api/v1/orders/{$this->order->id}", ['gross_quantity' => 5, 'version' => $this->order->version])->assertForbidden();
    });

    it('re-checks the advance when the price changes before approval', function () {
        verifyPayment($this, recordPayment($this, $this->order, 150000)); // 15% of 10,000
        $order = $this->order->fresh();
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['unit_price_minor' => 50000, 'version' => $order->version])
            ->assertOk()->assertJsonPath('data.status.value', 'confirmed'); // now 30% of 5,000
        expect(Order::query()->find($order->id)->isProductionEligible())->toBeTrue();
    });
});
