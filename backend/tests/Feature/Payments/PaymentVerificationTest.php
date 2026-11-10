<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderPaymentStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentVerificationEvent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/* Total LKR 10,000.00 → required advance LKR 3,000.00 (300000 minor). */
beforeEach(function () {
    intakeWorld($this);
    Storage::fake('private');
    $this->order = createOrder($this);
});

it('records an advance payment with evidence, pending verification', function () {
    Notification::fake();
    $payment = recordPayment($this, $this->order, 300000);

    expect($payment->status)->toBe(PaymentStatus::PendingVerification)
        ->and($payment->getAttribute('recorded_by'))->toBe($this->owner->id)
        ->and($payment->slip_document_id)->not->toBeNull()
        ->and(PaymentVerificationEvent::query()->where('payment_id', $payment->id)->pluck('action')->map->value->all())->toBe(['submitted']);

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::PendingPaymentVerification)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::AdvancePending)
        ->and($order->verified_paid_minor)->toBe(0); // not counted until verified

    Notification::assertSentTo($this->second, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'payment.pending');
    Notification::assertNotSentTo($this->secondB, WorkflowNotification::class);
});

it('validates payments: evidence and reference for bank transfers, no future dates, no overpayment', function () {
    $post = fn (array $data) => $this->signedInAs($this->owner)->post("/api/v1/orders/{$this->order->id}/payments", $data, ['Accept' => 'application/json']);

    $post(['amount_minor' => 300000, 'method' => 'bank_transfer', 'payment_date' => '2026-11-10'])
        ->assertStatus(422)->assertJsonValidationErrors(['evidence', 'reference_no', 'payment_date']);
    $post(['amount_minor' => 0, 'method' => 'cod', 'payment_date' => '2026-11-09'])->assertStatus(422)->assertJsonValidationErrors(['amount_minor', 'method']);
    $post(['amount_minor' => 1000001, 'method' => 'cash', 'payment_date' => '2026-11-09'])->assertStatus(422)->assertJsonValidationErrors('amount_minor');
    $post(['amount_minor' => 1000, 'method' => 'cash', 'payment_date' => '2026-11-09', 'evidence' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')])
        ->assertStatus(422)->assertJsonValidationErrors('evidence');

    // Cash needs no slip.
    $post(['amount_minor' => 1000, 'method' => 'cash', 'payment_date' => '2026-11-09'])->assertCreated();
});

it('keeps a verified payment below 30% out of production and shows the missing amount', function () {
    Notification::fake();
    verifyPayment($this, recordPayment($this, $this->order, 299999));

    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$this->order->id}")
        ->assertJsonPath('data.status.value', 'pending_payment_verification')
        ->assertJsonPath('data.payment_status', 'advance_pending')
        ->assertJsonPath('data.advance.verified_minor', 299999)
        ->assertJsonPath('data.advance.shortfall_minor', 1)
        ->assertJsonPath('data.advance.meets_requirement', false)
        ->assertJsonPath('data.production_eligible', false);

    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum' && $n->context['shortfall_minor'] === 1);
    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'payment.confirmed');
    // Normal job: Operation Admin has not planned for it, so they are not notified.
    Notification::assertNotSentTo($this->ops, WorkflowNotification::class);
});

it('approves the order when the verified advance is exactly 30%', function () {
    verifyPayment($this, recordPayment($this, $this->order, 300000));

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Confirmed)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::AdvancePaid)
        ->and($order->isProductionEligible())->toBeTrue()
        ->and($order->advanceShortfallMinor())->toBe(0);
});

it('approves the order when the verified advance is above 30%, and marks full payment', function () {
    verifyPayment($this, recordPayment($this, $this->order, 1000000));

    expect($this->order->fresh()->status)->toBe(OrderStatus::Confirmed)
        ->and($this->order->fresh()->payment_status)->toBe(OrderPaymentStatus::Paid);
});

it('adds up several verified advance payments', function () {
    $first = recordPayment($this, $this->order, 100000);
    $second = recordPayment($this, $this->order, 200000);
    verifyPayment($this, $first);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPaymentVerification);

    verifyPayment($this, $second);
    expect($this->order->fresh()->status)->toBe(OrderStatus::Confirmed)->and($this->order->fresh()->verified_paid_minor)->toBe(300000);
});

it('rejects a payment with a reason and keeps the verification history', function () {
    Notification::fake();
    $payment = recordPayment($this, $this->order, 300000);

    $this->signedInAs($this->second)->postJson("/api/v1/payments/{$payment->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('reason');
    $this->signedInAs($this->second)->postJson("/api/v1/payments/{$payment->id}/reject", ['reason' => 'Slip amount does not match'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.rejection_reason', 'Slip amount does not match')
        ->assertJsonPath('data.verified_by.id', $this->second->id)
        ->assertJsonPath('data.history.0.action', 'submitted')
        ->assertJsonPath('data.history.1.action', 'rejected')
        ->assertJsonPath('data.order.status.value', 'payment_rejected');

    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'payment.rejected');
    expect(AuditLog::query()->where('action', 'payments.rejected')->exists())->toBeTrue();

    // The decision is final; a corrected slip is a new payment, which reopens verification.
    $this->signedInAs($this->second)->postJson("/api/v1/payments/{$payment->id}/verify")->assertStatus(409);
    recordPayment($this, $this->order, 300000);
    expect($this->order->fresh()->status)->toBe(OrderStatus::PendingPaymentVerification);
});

it('never lets the recorder verify their own payment', function () {
    $admin = $this->userWithRole(Roles::SUPER_ADMIN);
    $payment = recordPayment($this, $this->order, 300000, $admin);

    $this->signedInAs($admin)->postJson("/api/v1/payments/{$payment->id}/verify")->assertForbidden();
    $this->signedInAs($admin)->getJson("/api/v1/payments/{$payment->id}")->assertOk()->assertJsonPath('data.abilities.verify', false);
    $this->signedInAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/verify")->assertForbidden(); // no permission
    expect($payment->fresh()->status)->toBe(PaymentStatus::PendingVerification);
});

it('returns the first payment for a repeated Idempotency-Key', function () {
    $key = (string) Str::uuid();
    $send = fn () => $this->signedInAs($this->owner)->post("/api/v1/orders/{$this->order->id}/payments",
        ['amount_minor' => 300000, 'method' => 'cash', 'payment_date' => '2026-11-09'], ['Accept' => 'application/json', 'Idempotency-Key' => $key]);

    $first = $send()->assertCreated()->json('data.id');
    expect($send()->assertOk()->json('data.id'))->toBe($first)->and(Payment::query()->count())->toBe(1);

    $this->signedInAs($this->owner)->post("/api/v1/orders/{$this->order->id}/payments", ['amount_minor' => 1, 'method' => 'cash', 'payment_date' => '2026-11-09'], ['Accept' => 'application/json', 'Idempotency-Key' => 'nope'])
        ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
});

it('serves evidence only through a signed URL and the payment policy', function () {
    $payment = recordPayment($this, $this->order, 300000);
    $url = $this->signedInAs($this->second)->getJson("/api/v1/payments/{$payment->id}")->assertOk()->json('data.evidence.url');

    $this->signedInAs($this->second)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->signedInAs($this->second)->get("/api/v1/payments/{$payment->id}/evidence")->assertForbidden(); // unsigned
    $this->signedInAs($this->secondB)->get($url)->assertNotFound(); // other page, even with the link
});

it('lists the verification queue in scope only', function () {
    $mine = recordPayment($this, $this->order, 300000);
    $orderB = createOrder($this, ['page_id' => $this->pageB->id], $this->ownerB);
    recordPayment($this, $orderB, 300000, $this->ownerB);

    $this->signedInAs($this->second)->getJson('/api/v1/payments?filter[status]=pending_verification')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)->assertJsonPath('data.0.order.shortfall_minor', 300000);
    $this->signedInAs($this->secondB)->getJson("/api/v1/payments/{$mine->id}")->assertNotFound();
    $this->signedInAs($this->secondB)->postJson("/api/v1/payments/{$mine->id}/verify")->assertNotFound();
    $this->asGuest()->getJson('/api/v1/payments')->assertUnauthorized();
});

it('refuses payments on drafts', function () {
    $draft = createOrder($this, ['submit' => false]);
    $this->signedInAs($this->owner)->post("/api/v1/orders/{$draft->id}/payments", ['amount_minor' => 1000, 'method' => 'cash', 'payment_date' => '2026-11-09'], ['Accept' => 'application/json'])
        ->assertStatus(409);
});
