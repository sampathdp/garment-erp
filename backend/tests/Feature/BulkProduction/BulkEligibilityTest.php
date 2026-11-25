<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Actions\CreateBulkJobIfEligible;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Support\Roles;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    designWorld($this);
    $this->order = approvedOrder($this);
    $this->task = submitJobDetails($this, $this->order);
});

it('waits for every prerequisite and then creates the bulk job once, notifying the bulk designer and operations', function () {
    Notification::fake();

    fillList($this, $this->order);
    confirmList($this, $this->order)->assertOk();
    expect(BulkJob::query()->count())->toBe(0); // design not final yet

    submitVersion($this, $this->task)->assertOk();
    $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$this->task->id}/approve", ['channel' => 'whatsapp'])->assertOk();
    expect(BulkJob::query()->count())->toBe(0); // approved, but final files missing

    $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$this->task->id}/final", ['external_url' => 'https://drive.google.com/file/d/final/view'], ['Accept' => 'application/json'])->assertOk();

    $job = BulkJob::query()->sole();
    expect($job->order_id)->toBe($this->order->id)->and($job->bulk_designer_id)->toBe($this->bulkDesigner->id)->and($job->status->value)->toBe('pending_assignment');
    Notification::assertSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.job_ready');
    Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.job_ready');

    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$this->order->id}")->assertJsonPath('data.bulk.job.id', $job->id)
        ->assertJsonPath('data.bulk.checklist.0.met', true)->assertJsonPath('data.bulk.checklist.3.met', true);
});

it('creates the bulk job without an invoice: the price is set after printing', function () {
    fillList($this, $this->order);
    confirmList($this, $this->order)->assertOk();
    completeDesign($this, $this->task);

    $job = BulkJob::query()->sole();
    expect($job->getAttribute('invoice_id'))->toBeNull()
        ->and(Invoice::query()->count())->toBe(0);
    $checklist = collect($this->signedInAs($this->owner)->getJson("/api/v1/orders/{$this->order->id}")->json('data.bulk.checklist'))->pluck('met', 'key');
    expect($checklist->keys()->all())->toBe(['final_design', 'list_locked', 'advance', 'stage']);
});

it('never creates a duplicate bulk job', function () {
    fillList($this, $this->order);
    confirmList($this, $this->order)->assertOk();
    completeDesign($this, $this->task);
    expect(BulkJob::query()->count())->toBe(1);

    // Re-running the check, another verified payment, or a list correction + re-confirmation do not duplicate it.
    expect(app(CreateBulkJobIfEligible::class)->handle($this->order, $this->owner))->toBeNull();
    verifyPayment($this, recordPayment($this, $this->order->fresh(), 10000));
    $this->signedInAs($this->admin)->postJson('/api/v1/garment-lists/'.listModel($this->order)->id.'/corrections', ['reason' => 'Size change requested'])->assertOk();
    confirmList($this, $this->order)->assertOk();

    expect(BulkJob::query()->count())->toBe(1);
});

it('lists bulk jobs for operations and only own jobs for bulk designers', function () {
    fillList($this, $this->order);
    confirmList($this, $this->order)->assertOk();
    completeDesign($this, $this->task);

    $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order.order_no', $this->order->order_no);
    $this->signedInAs($this->bulkDesigner)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(1, 'data');
    $this->signedInAs($this->userWithRole(Roles::BULK_DESIGNER))->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(0, 'data');
    $this->signedInAs($this->designer)->getJson('/api/v1/bulk-jobs')->assertForbidden();
});
