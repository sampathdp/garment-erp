<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJobAssignment;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    bulkWorld($this);
    $this->job = readyBulkJob($this);
    $this->order = Order::query()->findOrFail($this->job->order_id);
});

describe('internal path', function () {
    it('runs assignment → measurements → checklist → review → approval → final submission and notifies at every step', function () {
        Notification::fake();

        assignInternal($this, $this->job)->assertOk()
            ->assertJsonPath('data.mode', 'internal')->assertJsonPath('data.status.value', 'assigned')
            ->assertJsonPath('data.bulk_designer.id', $this->bulkDesigner->id)
            ->assertJsonPath('data.assignment.history.0.assigned_by', $this->ops->name)
            ->assertJsonPath('data.assignment.history.0.notes', 'Priority club order')
            ->assertJsonPath('data.assignment.history.0.status', 'current');
        expect($this->order->fresh()->status->value)->toBe('bulk_assigned');
        Notification::assertSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.assigned_internal');

        enterMeasurements($this, $this->job)->assertOk()->assertJsonPath('data.status.value', 'in_progress');
        expect($this->order->fresh()->status->value)->toBe('bulk_preparation');
        completeChecklist($this, $this->job);

        submitBulk($this, $this->job)->assertOk()->assertJsonPath('data.status.value', 'in_review')
            ->assertJsonPath('data.versions.0.version_no', 1)->assertJsonCount(2, 'data.versions.0.files');
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.submitted');

        approveBulk($this, $this->job)->assertOk()->assertJsonPath('data.status.value', 'approved')
            ->assertJsonPath('data.versions.0.is_approved', true)->assertJsonPath('data.review.approved_by', $this->owner->name);
        $job = $this->job->fresh();
        expect($job->approved_list_version_id)->toBe($job->garment_list_version_id)->not->toBeNull();
        Notification::assertSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.approved');

        confirmChecklistWithClient($this, $this->job)->assertOk()->assertJsonPath('data.checklist.client_confirmation.channel', 'whatsapp')
            ->assertJsonPath('data.checklist.client_confirmation.recorded_by', $this->owner->name);

        submitFinalBulk($this, $this->job)->assertOk()->assertJsonPath('data.status.value', 'completed')
            ->assertJsonPath('data.versions.0.is_final', true)->assertJsonPath('data.versions.0.transfer_url', wetransferLink())
            ->assertJsonPath('data.completion.completed_by', $this->bulkDesigner->name);
        expect($this->order->fresh()->status->value)->toBe('print_preparation');
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.completed');
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.completed');

        // Duplicate completion.
        submitFinalBulk($this, $this->job)->assertStatus(409);
        expect(BulkVersion::query()->where('bulk_job_id', $this->job->id)->where('is_final', true)->count())->toBe(1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bulk.completed']);
    });

    it('requires an approved bulk, final files and an allow-listed WeTransfer link', function () {
        assignInternal($this, $this->job)->assertOk();
        enterMeasurements($this, $this->job)->assertOk();
        completeChecklist($this, $this->job);
        submitBulk($this, $this->job)->assertOk();
        submitFinalBulk($this, $this->job)->assertStatus(409); // not approved yet

        approveBulk($this, $this->job)->assertOk();
        confirmChecklistWithClient($this, $this->job)->assertOk();
        submitFinalBulk($this, $this->job, ['transfer_url' => null])->assertStatus(422)->assertJsonValidationErrors('transfer_url');
        submitFinalBulk($this, $this->job, ['transfer_url' => 'https://evil.example.com/downloads/x'])->assertStatus(422)->assertJsonValidationErrors('transfer_url');
        submitFinalBulk($this, $this->job, ['transfer_url' => 'http://wetransfer.com/downloads/x'])->assertStatus(422);
        submitFinalBulk($this, $this->job, ['files' => []])->assertStatus(422)->assertJsonValidationErrors('files');
        expect($this->job->fresh()->status->value)->toBe('approved');

        submitFinalBulk($this, $this->job, ['transfer_url' => 'https://we.tl/t-abc123'])->assertOk();
    });

    it('lets only the assigned designer work on an internal job', function () {
        assignInternal($this, $this->job)->assertOk();
        $other = $this->userWithRole(Roles::BULK_DESIGNER);

        enterMeasurements($this, $this->job, as: $other)->assertNotFound();
        submitBulk($this, $this->job, $other)->assertNotFound();
        enterMeasurements($this, $this->job, as: $this->ops)->assertForbidden();
        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/external/complete", ['version' => jobVersion($this->job)])->assertForbidden();
    });
});

describe('external path', function () {
    it('is completed by the Operation Admin without designer completion or a WeTransfer link', function () {
        Notification::fake();

        assignExternal($this, $this->job)->assertOk()->assertJsonPath('data.mode', 'external')
            ->assertJsonPath('data.supplier.name', 'Print House Ltd')->assertJsonPath('data.bulk_designer', null)
            ->assertJsonPath('data.checklist.stage', 'bulk_external');
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.assigned_external');
        // The responsible designer pre-filled at creation no longer sees the job.
        $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/bulk-jobs/{$this->job->id}")->assertNotFound();

        enterMeasurements($this, $this->job, ['bulk_meter' => '20', 'patch_meter' => '4.5'], $this->ops)->assertOk();
        completeChecklist($this, $this->job, $this->ops);
        $this->signedInAs($this->ops)->post("/api/v1/bulk-jobs/{$this->job->id}/external/evidence", ['file' => pngFile('returned.png'), 'note' => 'Bulk received'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.evidence.0.note', 'Bulk received');
        confirmChecklistWithClient($this, $this->job)->assertOk();

        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/external/complete", ['version' => jobVersion($this->job), 'notes' => 'Received from Print House'])
            ->assertOk()->assertJsonPath('data.status.value', 'completed')->assertJsonPath('data.completion.notes', 'Received from Print House');
        expect($this->order->fresh()->status->value)->toBe('print_preparation')
            ->and(BulkVersion::query()->where('bulk_job_id', $this->job->id)->count())->toBe(0);
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.completed');

        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/external/complete", ['version' => jobVersion($this->job)])->assertStatus(409);
    });

    it('validates the same mandatory production data before external completion', function () {
        assignExternal($this, $this->job)->assertOk();

        $response = $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/external/complete", ['version' => jobVersion($this->job)])
            ->assertStatus(409)->assertJsonPath('code', 'bulk_incomplete');
        expect(array_keys($response->json('errors')))->toEqualCanonicalizing(['measurements', 'checklist'])
            ->and($response->json('errors.measurements'))->toContain('Bulk Meter is required.', 'Patch Meter is required.');

        enterMeasurements($this, $this->job, ['bulk_meter' => '20', 'patch_meter' => '4.5'], $this->ops)->assertOk();
        completeChecklist($this, $this->job, $this->ops);
        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/external/complete", ['version' => jobVersion($this->job)])
            ->assertStatus(409)->assertJsonPath('errors.checklist.0', 'The client has not confirmed the final checklist.');

        // External jobs never accept internal submissions.
        submitBulk($this, $this->job, $this->ops)->assertForbidden();
    });

    it('restricts editing of external assignments', function () {
        assignExternal($this, $this->job)->assertOk();
        $other = Supplier::query()->create(['name' => 'Other Printer', 'service_type' => 'full', 'is_active' => true]);

        assignExternal($this, $this->job, $other, extra: ['reason' => 'Cheaper'])->assertForbidden()->assertJsonPath('code', 'external_assignment_restricted');
        assignInternal($this, $this->job, extra: ['reason' => 'Bring in-house'])->assertForbidden();

        // An administrator with bulk.assignment_override can change it, with a reason.
        assignInternal($this, $this->job, as: $this->admin)->assertStatus(422)->assertJsonValidationErrors('reason');
        assignInternal($this, $this->job, as: $this->admin, extra: ['reason' => 'Supplier unavailable'])->assertOk()
            ->assertJsonPath('data.mode', 'internal')->assertJsonPath('data.checklist.stage', 'bulk_preparation')
            ->assertJsonPath('data.assignment.history.0.reason', 'Supplier unavailable')->assertJsonPath('data.assignment.history.1.status', 'ended');
        expect(BulkJobAssignment::query()->where('bulk_job_id', $this->job->id)->count())->toBe(2)
            ->and(DB::table('checklist_runs')->where('bulk_job_id', $this->job->id)->whereNotNull('superseded_at')->count())->toBe(1);
    });
});

describe('dashboards', function () {
    it('shows the job information sorted by due date, to operations in full and to designers only their jobs', function () {
        $sooner = readyBulkJob($this, ['due_date' => '2026-11-20']);
        assignInternal($this, $sooner)->assertOk();
        assignExternal($this, $this->job)->assertOk();

        $rows = $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs')->assertOk()->json('data');
        expect(array_column($rows, 'id'))->toBe([$sooner->id, $this->job->id]);
        expect($rows[0])->toMatchArray(['job_no' => $sooner->order->order_no, 'branch' => 'CMB', 'product_category' => 'Jersey', 'material' => 'Wet look', 'final_design_link' => 'https://drive.google.com/file/d/abc123/view'])
            ->and($rows[0]['order'])->toMatchArray(['customer' => 'Nimal Perera', 'due_date' => '2026-11-20', 'quantity' => 10])
            ->and($rows[0]['list'])->toMatchArray(['version_no' => 1, 'total_quantity' => 10, 'status' => 'locked'])
            ->and($rows[0]['design_preview_url'])->toContain('/bulk-jobs/'.$sooner->id.'/documents/')
            ->and($rows[0]['status']['value'])->toBe('assigned');

        $this->signedInAs($this->bulkDesigner)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sooner->id);
        $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs?filter[mode]=external')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->job->id);
        $this->signedInAs($this->ownerB)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('lists without N+1 queries', function () {
        readyBulkJob($this, ['due_date' => '2026-11-21']);
        readyBulkJob($this, ['due_date' => '2026-11-22']);
        $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs')->assertOk(); // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(3, 'data');
        $three = count(DB::getQueryLog());
        DB::disableQueryLog();

        readyBulkJob($this, ['due_date' => '2026-11-23']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs')->assertOk()->assertJsonCount(4, 'data');
        expect(count(DB::getQueryLog()))->toBe($three);
    });

    it('serves the approved design preview and the confirmed list to the assigned designer only', function () {
        assignInternal($this, $this->job)->assertOk();
        $data = bulkJob($this, $this->job, $this->bulkDesigner);

        $this->signedInAs($this->bulkDesigner)->get($data['design_preview_url'])->assertOk();
        $this->signedInAs($this->bulkDesigner)->get(str_replace('/documents/', '/documents/9', $data['design_preview_url']))->assertForbidden(); // tampered signature
        $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/bulk-jobs/{$this->job->id}/garment-list")->assertOk()
            ->assertJsonCount(10, 'data.items')->assertJsonPath('data.version.status', 'locked');
        $this->signedInAs($this->userWithRole(Roles::BULK_DESIGNER))->getJson("/api/v1/bulk-jobs/{$this->job->id}/garment-list")->assertNotFound();
        $this->asGuest()->getJson("/api/v1/bulk-jobs/{$this->job->id}")->assertUnauthorized();
    });
});

describe('job card', function () {
    it('is assembled from the authoritative records and follows every change automatically', function () {
        assignInternal($this, $this->job)->assertOk();
        $card = $this->signedInAs($this->ops)->getJson("/api/v1/bulk-jobs/{$this->job->id}/job-card")->assertOk()->json('data');
        expect($card)->toMatchArray(['job_no' => $this->order->order_no, 'material' => 'Wet look', 'quantity' => 10, 'final_design_link' => 'https://drive.google.com/file/d/abc123/view'])
            ->and($card['product']['category'])->toBe('Jersey')
            ->and($card['bulk_meter'])->toMatchArray(['label' => 'Bulk Meter', 'value' => null, 'unit' => 'm'])
            ->and(collect($card['other_measurements'])->pluck('key')->all())->toBe(['collar_meter'])
            ->and($card['list'])->toMatchArray(['version_no' => 1, 'total_quantity' => 10, 'is_current' => true])
            ->and(collect($card['list']['by_size'])->pluck('quantity', 'size')->all())->toBe(['S' => 4, 'M' => 6])
            ->and($card['design_image_url'])->not->toBeNull();

        enterMeasurements($this, $this->job, ['bulk_meter' => '12.5', 'patch_meter' => '3.25', 'collar_meter' => '0.8'])->assertOk();
        $card = $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/bulk-jobs/{$this->job->id}/job-card")->assertOk()->json('data');
        expect($card['bulk_meter']['value'])->toBe('12.50')->and($card['patch_meter']['value'])->toBe('3.25')
            ->and($card['other_measurements'][0]['value'])->toBe('0.80');

        enterMeasurements($this, $this->job, ['bulk_meter' => '13'])->assertOk();
        expect($this->signedInAs($this->ops)->getJson("/api/v1/bulk-jobs/{$this->job->id}/job-card")->json('data.bulk_meter.value'))->toBe('13.00');

        // No copy of the values is stored anywhere else.
        expect(DB::table('bulk_job_measurements')->where('bulk_job_id', $this->job->id)->count())->toBe(3);
        $this->signedInAs($this->ownerB)->getJson("/api/v1/bulk-jobs/{$this->job->id}/job-card")->assertNotFound();
    });
});
