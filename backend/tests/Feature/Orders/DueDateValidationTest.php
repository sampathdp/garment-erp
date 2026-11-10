<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\UrgentApproval;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Holiday;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
 * Today is 2026-11-09 (Asia/Colombo). Days are counted AFTER today up to and including the due date.
 *   2026-11-16 → 7 days → normal       2026-11-15 → 6 days → urgent
 */
beforeEach(fn () => intakeWorld($this));

it('treats a job exactly seven days away as a normal job', function () {
    $order = createOrder($this, ['due_date' => '2026-11-16']);

    expect($order->status)->toBe(OrderStatus::PendingPaymentVerification)
        ->and($order->is_urgent)->toBeFalse()
        ->and($order->urgent_status)->toBe(UrgentStatus::NotRequired)
        ->and(UrgentApproval::query()->count())->toBe(0);
});

it('marks a job six days away as urgent and asks both admins for approval', function () {
    Notification::fake();
    $order = createOrder($this, ['due_date' => '2026-11-15']);

    expect($order->status)->toBe(OrderStatus::UrgentApprovalPending)
        ->and($order->is_urgent)->toBeTrue()
        ->and($order->urgent_status)->toBe(UrgentStatus::Pending);

    $request = UrgentApproval::query()->sole();
    expect($request->working_days_available)->toBe(6)
        ->and($request->reference_date->toDateString())->toBe('2026-11-09')
        ->and($request->evaluated_delivery_date->toDateString())->toBe('2026-11-15');

    $urgent = fn (User $u) => Notification::assertSentTo($u, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'urgent.approval_requested');
    $urgent($this->second);
    $urgent($this->ops);
    Notification::assertNotSentTo($this->secondB, WorkflowNotification::class);

    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.urgent_approval.days_available', 6)
        ->assertJsonPath('data.urgent_approval.slots.0.slot', 'second_admin')
        ->assertJsonPath('data.urgent_approval.slots.1.slot', 'operation_admin')
        ->assertJsonPath('data.approval_status', 'Awaiting urgent approval (0 of 2)');
});

it('uses the server date, not the client, around midnight in Colombo', function () {
    // 23:30 on the 9th in Colombo is still the 9th, although it is 18:00 UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30:00', 'Asia/Colombo'));
    expect(createOrder($this, ['due_date' => '2026-11-16'])->is_urgent)->toBeFalse();

    // Five minutes later it is the 10th: the same due date is only 6 days away.
    $this->travelTo(CarbonImmutable::parse('2026-11-10 00:05:00', 'Asia/Colombo'));
    expect(createOrder($this, ['due_date' => '2026-11-16'])->is_urgent)->toBeTrue();
});

it('counts working days: holidays and days off make a seven-calendar-day job urgent', function () {
    Holiday::query()->create(['holiday_date' => '2026-11-12', 'name' => 'Poya', 'created_by' => $this->owner->id]);
    expect(createOrder($this, ['due_date' => '2026-11-16'])->is_urgent)->toBeTrue();

    Holiday::query()->delete();
    WorkingCalendarDay::query()->create(['organization_id' => $this->branch->organization_id, 'iso_weekday' => 7, 'is_working_day' => false]);
    // 10th–23rd = 14 days minus 2 Sundays = 12 working days → normal; 10th–16th = 7 minus 1 Sunday = 6 → urgent.
    expect(createOrder($this, ['due_date' => '2026-11-23'])->is_urgent)->toBeFalse()
        ->and(createOrder($this, ['due_date' => '2026-11-16'])->is_urgent)->toBeTrue();
});

it('ignores holidays of other branches', function () {
    $other = Branch::factory()->create();
    Holiday::query()->create(['branch_id' => $other->id, 'holiday_date' => '2026-11-12', 'name' => 'Closed', 'created_by' => $this->owner->id]);

    expect(createOrder($this, ['due_date' => '2026-11-16'])->is_urgent)->toBeFalse();
});

it('rejects due dates today or in the past', function () {
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['due_date' => '2026-11-09']))->assertStatus(422)->assertJsonValidationErrors('due_date');
    $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, ['due_date' => '2026-11-01']))->assertStatus(422)->assertJsonValidationErrors('due_date');
});

it('cannot be bypassed by editing the due date after submission', function () {
    Notification::fake();
    $order = createOrder($this, ['due_date' => '2026-12-31']);

    $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['due_date' => '2026-11-12', 'version' => $order->version])
        ->assertOk()->assertJsonPath('data.status.value', 'urgent_approval_pending')->assertJsonPath('data.urgent.is_urgent', true);
    Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'urgent.approval_requested');

    // Moving it back out of the urgent window closes the request and returns the order to payment.
    $order->refresh();
    $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['due_date' => '2026-11-20', 'version' => $order->version])
        ->assertOk()->assertJsonPath('data.status.value', 'pending_payment_verification')->assertJsonPath('data.urgent.is_urgent', false);

    expect(UrgentApproval::query()->whereNotNull('superseded_at')->count())->toBe(1);
});

it('re-evaluates a rejected urgent job when its due date is changed', function () {
    $order = createOrder($this, ['due_date' => '2026-11-12']);
    $this->signedInAs($this->ops)->postJson("/api/v1/orders/{$order->id}/urgent-decisions", ['slot' => 'operation_admin', 'decision' => 'rejected', 'reason' => 'No capacity'])->assertOk();
    $order->refresh();
    expect($order->status)->toBe(OrderStatus::UrgentApprovalRejected);

    $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['due_date' => '2026-11-14', 'version' => $order->version])
        ->assertOk()->assertJsonPath('data.status.value', 'urgent_approval_pending')->assertJsonPath('data.urgent_approval.slots.1.decision', null);
});
