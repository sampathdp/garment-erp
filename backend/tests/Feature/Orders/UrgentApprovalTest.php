<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\UrgentStatus;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

beforeEach(function () {
    intakeWorld($this);
    $this->order = createOrder($this, ['due_date' => '2026-11-12']); // 3 days → urgent
});

function decide(TestCase $test, $user, string $slot, string $decision = 'approved', ?string $reason = null)
{
    return $test->signedInAs($user)->postJson("/api/v1/orders/{$test->order->id}/urgent-decisions", array_filter(['slot' => $slot, 'decision' => $decision, 'reason' => $reason]));
}

it('keeps the job unreleased with only one approval, even when the advance is paid', function () {
    verifyPayment($this, recordPayment($this, $this->order, 300000));
    decide($this, $this->second, 'second_admin')->assertOk()
        ->assertJsonPath('data.status.value', 'urgent_approval_pending')
        ->assertJsonPath('data.approval_status', 'Awaiting urgent approval (1 of 2)')
        ->assertJsonPath('data.advance.meets_requirement', true)
        ->assertJsonPath('data.production_eligible', false);

    expect($this->order->fresh()->urgent_status)->toBe(UrgentStatus::Pending);
});

it('releases the job after both approvals and a verified 30% advance', function () {
    Notification::fake();
    verifyPayment($this, recordPayment($this, $this->order, 300000));
    decide($this, $this->ops, 'operation_admin')->assertOk();
    Notification::assertSentTo($this->second, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'urgent.slot_decided');

    decide($this, $this->second, 'second_admin')->assertOk()
        ->assertJsonPath('data.status', ['value' => 'confirmed', 'label' => 'Approved Order'])
        ->assertJsonPath('data.approval_status', 'Urgent approved')
        ->assertJsonPath('data.production_eligible', true);

    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'urgent.approved');
});

it('waits for the advance after both approvals, and notifies the approving Operation Admin of the shortfall', function () {
    Notification::fake();
    decide($this, $this->second, 'second_admin')->assertOk();
    decide($this, $this->ops, 'operation_admin')->assertOk()->assertJsonPath('data.status.value', 'pending_payment_verification');

    Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum');
    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum');

    verifyPayment($this, recordPayment($this, $this->order, 300000));
    expect($this->order->fresh()->status)->toBe(OrderStatus::Confirmed);
});

it('rejects the urgent job when either admin rejects, with a required reason', function () {
    Notification::fake();
    decide($this, $this->second, 'second_admin', 'rejected')->assertStatus(422)->assertJsonValidationErrors('reason');
    decide($this, $this->second, 'second_admin')->assertOk();
    decide($this, $this->ops, 'operation_admin', 'rejected', 'Printer fully booked')->assertOk()
        ->assertJsonPath('data.status.value', 'urgent_approval_rejected')
        ->assertJsonPath('data.urgent_approval.decision', 'rejected')
        ->assertJsonPath('data.urgent_approval.slots.1.reason', 'Printer fully booked');

    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'urgent.rejected');

    // A full advance does not release a rejected urgent job.
    verifyPayment($this, recordPayment($this, $this->order, 300000));
    expect($this->order->fresh()->status)->toBe(OrderStatus::UrgentApprovalRejected);
    decide($this, $this->second, 'operation_admin')->assertForbidden();
});

it('records each slot only once and needs two different people', function () {
    decide($this, $this->second, 'second_admin')->assertOk();
    $secondAgain = $this->userWithRole(Roles::SECOND_ADMIN, pages: [$this->pageA]);
    decide($this, $secondAgain, 'second_admin')->assertStatus(409)->assertJsonPath('code', 'already_decided');

    $admin = $this->userWithRole(Roles::SUPER_ADMIN);
    $other = createOrder($this, ['due_date' => '2026-11-11']);
    $this->order = $other;
    decide($this, $admin, 'second_admin')->assertOk();
    decide($this, $admin, 'operation_admin')->assertForbidden()->assertJsonPath('code', 'separation_of_duties');
    expect(UrgentApprovalDecision::query()->count())->toBe(2);
});

it('enforces slot permission, page scope and state', function () {
    decide($this, $this->owner, 'second_admin')->assertForbidden();         // Page Owner holds neither slot
    decide($this, $this->second, 'operation_admin')->assertForbidden();     // wrong slot for the role
    decide($this, $this->secondB, 'second_admin')->assertNotFound();        // other page
    decide($this, $this->second, 'bogus')->assertStatus(422)->assertJsonValidationErrors('slot');
    $this->asGuest()->postJson("/api/v1/orders/{$this->order->id}/urgent-decisions", ['slot' => 'second_admin', 'decision' => 'approved'])->assertUnauthorized();

    $this->order = createOrder($this, ['due_date' => '2026-12-31']); // not urgent
    decide($this, $this->second, 'second_admin')->assertStatus(409);
});

it('exposes per-slot abilities to the frontend', function () {
    $this->signedInAs($this->second)->getJson("/api/v1/orders/{$this->order->id}")
        ->assertJsonPath('data.abilities.decide_urgent_second_admin', true)
        ->assertJsonPath('data.abilities.decide_urgent_operation_admin', false);
    decide($this, $this->second, 'second_admin')->assertOk()->assertJsonPath('data.abilities.decide_urgent_second_admin', false);
});
