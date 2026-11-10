<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Actions\ReviewBulk;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobMeasurementChange;
use App\Modules\BulkProduction\Models\BulkReview;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    bulkWorld($this);
    $this->job = readyBulkJob($this);
});

describe('measurements', function () {
    it('validates values against each field and records who entered and changed them', function () {
        assignInternal($this, $this->job)->assertOk();

        enterMeasurements($this, $this->job, ['bulk_meter' => '-1'])->assertStatus(422)->assertJsonValidationErrors('values.bulk_meter');
        enterMeasurements($this, $this->job, ['bulk_meter' => '12.555'])->assertStatus(422)->assertJsonValidationErrors(['values.bulk_meter' => 'Bulk Meter can have at most 2 decimal place(s).']);
        enterMeasurements($this, $this->job, ['bulk_meter' => '0'])->assertStatus(422);         // below the 0.01 minimum
        enterMeasurements($this, $this->job, ['bulk_meter' => '20000'])->assertStatus(422);     // above the maximum
        enterMeasurements($this, $this->job, ['bulk_meter' => 'abc'])->assertStatus(422);
        enterMeasurements($this, $this->job, ['chest' => '10'])->assertStatus(422)->assertJsonValidationErrors('values.chest');
        enterMeasurements($this, $this->job, ['bulk_meter' => '12', 'patch_meter' => '1e3'])->assertStatus(422);
        expect(BulkJobMeasurementChange::query()->count())->toBe(0); // nothing saved partially

        enterMeasurements($this, $this->job, ['bulk_meter' => 12.5, 'patch_meter' => '3'])->assertOk()
            ->assertJsonPath('data.measurements.0.value', '12.50')->assertJsonPath('data.measurements.0.entered_by', $this->bulkDesigner->name);
        enterMeasurements($this, $this->job, ['bulk_meter' => '12.50'])->assertOk(); // unchanged: no history row
        enterMeasurements($this, $this->job, ['bulk_meter' => '14'])->assertOk()->assertJsonPath('data.measurements.0.updated_by', $this->bulkDesigner->name);

        $history = BulkJobMeasurementChange::query()->orderBy('id')->get();
        expect($history)->toHaveCount(3)
            ->and($history->last()->old_value)->toBe('12.500')->and($history->last()->new_value)->toBe('14.000')
            ->and($history->last()->getAttribute('changed_by'))->toBe($this->bulkDesigner->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'bulk.measurements_recorded']);
    });

    it('freezes measurements in review and after completion, allowing only an authorised correction with a reason', function () {
        assignInternal($this, $this->job)->assertOk();
        enterMeasurements($this, $this->job)->assertOk();
        completeChecklist($this, $this->job);
        submitBulk($this, $this->job)->assertOk();
        enterMeasurements($this, $this->job, ['bulk_meter' => '30'])->assertStatus(409); // in review

        approveBulk($this, $this->job)->assertOk();
        confirmChecklistWithClient($this, $this->job)->assertOk();
        submitFinalBulk($this, $this->job)->assertOk();

        enterMeasurements($this, $this->job, ['bulk_meter' => '30'])->assertStatus(409);
        enterMeasurements($this, $this->job, ['bulk_meter' => '30'], $this->ops)->assertForbidden(); // internal job: designer only
        $correct = fn ($user, array $body) => $this->signedInAs($user)->postJson("/api/v1/bulk-jobs/{$this->job->id}/measurements/corrections", $body);
        $correct($this->ops, ['values' => ['bulk_meter' => '30'], 'reason' => 'Re-measured'])->assertForbidden();
        $correct($this->admin, ['values' => ['bulk_meter' => '30']])->assertStatus(422)->assertJsonValidationErrors('reason');
        $correct($this->admin, ['values' => ['bulk_meter' => '30'], 'reason' => 'Re-measured after cutting test'])->assertOk();

        $change = BulkJobMeasurementChange::query()->latest('id')->first();
        expect($change->is_correction)->toBeTrue()->and($change->getAttribute('reason'))->toBe('Re-measured after cutting test')
            ->and($this->signedInAs($this->ops)->getJson("/api/v1/bulk-jobs/{$this->job->id}/job-card")->json('data.bulk_meter.value'))->toBe('30.00');
        $this->assertDatabaseHas('audit_logs', ['action' => 'bulk.measurements_corrected']);
    });

    it('rejects work before assignment and on other branches/pages', function () {
        enterMeasurements($this, $this->job)->assertStatus(409);
        enterMeasurements($this, $this->job, as: $this->ownerB)->assertNotFound();
    });
});

describe('checklist', function () {
    it('gives every job its own instance and blocks completion while mandatory items are open', function () {
        assignInternal($this, $this->job)->assertOk();
        $other = readyBulkJob($this, ['due_date' => '2026-11-25']);
        assignInternal($this, $other)->assertOk();
        $items = ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $this->job->id))->get();
        expect($items)->toHaveCount(5)
            ->and(ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $other->id))->count())->toBe(5);

        $first = $items->first();
        $this->signedInAs($this->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$this->job->id}/checklist/items/{$first->id}", ['is_completed' => true, 'notes' => 'All 10 names checked'])
            ->assertOk()->assertJsonPath('data.checklist.items.0.is_completed', true)->assertJsonPath('data.checklist.items.0.completed_by', $this->bulkDesigner->name)
            ->assertJsonPath('data.checklist.items.0.notes', 'All 10 names checked');
        $this->signedInAs($this->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$this->job->id}/checklist/items/{$first->id}", ['is_completed' => false])
            ->assertOk()->assertJsonPath('data.checklist.items.0.completed_by', null)->assertJsonPath('data.checklist.items.0.completed_at', null);
        // Another job's item cannot be reached through this job.
        $foreign = ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $other->id))->first();
        $this->signedInAs($this->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$this->job->id}/checklist/items/{$foreign->id}", ['is_completed' => true])->assertNotFound();

        // A template change never alters the existing instances.
        ChecklistTemplate::query()->update(['is_active' => false]);
        expect(ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $this->job->id))->count())->toBe(5);

        confirmChecklistWithClient($this, $this->job)->assertStatus(409)->assertJsonPath('code', 'checklist_incomplete');

        enterMeasurements($this, $this->job)->assertOk();
        submitBulk($this, $this->job)->assertOk();
        approveBulk($this, $this->job)->assertOk();
        $response = submitFinalBulk($this, $this->job)->assertStatus(409)->assertJsonPath('code', 'bulk_incomplete');
        expect($response->json('errors.checklist'))->toContain('Not completed: Names and numbers match the confirmed list');
    });

    it('locks the checklist once the client confirmed it, and reopens it when a correction is requested', function () {
        assignInternal($this, $this->job)->assertOk();
        completeChecklist($this, $this->job);
        confirmChecklistWithClient($this, $this->job, $this->ops)->assertForbidden();      // only page owners record it
        confirmChecklistWithClient($this, $this->job, $this->bulkDesigner)->assertForbidden();
        $this->signedInAs($this->owner)->post("/api/v1/bulk-jobs/{$this->job->id}/checklist/client-confirmation", ['channel' => 'messenger', 'evidence' => pngFile('chat.png')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.checklist.client_confirmation.channel', 'messenger');
        confirmChecklistWithClient($this, $this->job)->assertStatus(409)->assertJsonPath('code', 'checklist_already_confirmed');

        $item = ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $this->job->id))->first();
        $this->signedInAs($this->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$this->job->id}/checklist/items/{$item->id}", ['is_completed' => false])
            ->assertStatus(409)->assertJsonPath('code', 'checklist_confirmed');

        submitBulk($this, $this->job)->assertOk();
        requestBulkCorrection($this, $this->job)->assertOk()->assertJsonPath('data.checklist.client_confirmation', null);
        $this->signedInAs($this->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$this->job->id}/checklist/items/{$item->id}", ['notes' => 'Rechecked'])->assertOk();
    });
});

describe('review', function () {
    it('keeps the correction history and every previous version', function () {
        Notification::fake();
        assignInternal($this, $this->job)->assertOk();
        submitBulk($this, $this->job)->assertOk();

        $this->signedInAs($this->owner)->postJson("/api/v1/bulk-jobs/{$this->job->id}/corrections", ['version' => jobVersion($this->job)])->assertStatus(422)->assertJsonValidationErrors('reason');
        requestBulkCorrection($this, $this->job, 'Number 7 is too small')->assertOk()->assertJsonPath('data.status.value', 'changes_requested');
        Notification::assertSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'bulk.correction_requested');

        submitBulk($this, $this->job, extra: ['notes' => 'Bigger numbers'])->assertOk();
        requestBulkCorrection($this, $this->job, 'Wrong sleeve colour')->assertOk();
        $data = submitBulk($this, $this->job)->assertOk()->json('data');

        expect(array_column($data['versions'], 'version_no'))->toBe([3, 2, 1])
            ->and($data['review']['correction_rounds'])->toBe(2)
            ->and($data['versions'][2]['reviews'][0])->toMatchArray(['decision' => 'changes_requested', 'reason' => 'Number 7 is too small', 'round_no' => 1])
            ->and($data['versions'][1]['reviews'][0])->toMatchArray(['reason' => 'Wrong sleeve colour', 'round_no' => 2])
            ->and($data['versions'][0]['is_current'])->toBeTrue()
            ->and(count($data['versions'][2]['files']))->toBe(2);
        expect(fn () => BulkReview::query()->first()->update(['reason' => 'x']))->toThrow(LogicException::class);
    });

    it('lets only the Page Owner of the page review, and only a bulk in review', function () {
        assignInternal($this, $this->job)->assertOk();
        approveBulk($this, $this->job)->assertStatus(409); // nothing submitted yet
        submitBulk($this, $this->job)->assertOk();

        $member = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]); // page member, not owner
        approveBulk($this, $this->job, $this->ops)->assertForbidden();
        approveBulk($this, $this->job, $this->bulkDesigner)->assertForbidden();
        approveBulk($this, $this->job, $member)->assertForbidden()->assertJsonPath('code', 'not_page_owner');
        approveBulk($this, $this->job, $this->ownerB)->assertNotFound();
        requestBulkCorrection($this, $this->job, as: $this->ops)->assertForbidden();
        approveBulk($this, $this->job)->assertOk();
        approveBulk($this, $this->job)->assertStatus(409);
    });
});

describe('list locking', function () {
    it('invalidates an approved bulk when the list is reopened and locks the list once the bulk is completed', function () {
        approvedInternalBulk($this, $this->job);
        $listId = listModel($this->job->order)->id;
        $approvedListVersion = $this->job->fresh()->approved_list_version_id;

        $this->signedInAs($this->admin)->postJson("/api/v1/garment-lists/{$listId}/corrections", ['reason' => 'Player 3 name misspelled'])->assertOk();
        $data = bulkJob($this, $this->job);
        expect($data['status']['value'])->toBe('changes_requested')
            ->and($data['review']['approved_list_version_no'])->toBeNull()
            ->and($data['versions'][0]['reviews'][1])->toMatchArray(['decision' => 'changes_requested', 'is_system' => true])
            ->and($data['checklist']['client_confirmation'])->toBeNull();

        submitBulk($this, $this->job)->assertStatus(409)->assertJsonPath('code', 'list_not_locked');

        confirmList($this, $this->job->order)->assertOk();
        $job = $this->job->fresh();
        expect($job->garment_list_version_id)->not->toBe($approvedListVersion);

        submitBulk($this, $this->job)->assertOk()->assertJsonPath('data.versions.0.list_version_no', 2);
        approveBulk($this, $this->job)->assertOk();
        expect($this->job->fresh()->approved_list_version_id)->toBe($job->garment_list_version_id);
        confirmChecklistWithClient($this, $this->job)->assertOk();
        submitFinalBulk($this, $this->job)->assertOk();

        $this->signedInAs($this->admin)->postJson("/api/v1/garment-lists/{$listId}/corrections", ['reason' => 'Late change'])
            ->assertStatus(409)->assertJsonPath('code', 'bulk_completed');
    });
});

describe('assignment', function () {
    it('validates the assignee, requires a reason to reassign and keeps the history', function () {
        assignInternal($this, $this->job, $this->designer)->assertStatus(422)->assertJsonValidationErrors('bulk_designer_id'); // design designer: no bulk.measure
        assignExternal($this, $this->job, extra: ['supplier_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        assignInternal($this, $this->job, as: $this->bulkDesigner)->assertForbidden();
        assignInternal($this, $this->job, as: $this->owner)->assertForbidden();
        assignInternal($this, $this->job, as: $this->ownerB)->assertNotFound();

        assignInternal($this, $this->job)->assertOk();
        $colleague = $this->userWithRole(Roles::BULK_DESIGNER);
        assignInternal($this, $this->job, $colleague)->assertStatus(422)->assertJsonValidationErrors('reason');
        assignInternal($this, $this->job, $this->bulkDesigner, extra: ['reason' => 'Same'])->assertStatus(409)->assertJsonPath('code', 'no_change');
        assignInternal($this, $this->job, $colleague, extra: ['reason' => 'Workload'])->assertOk()
            ->assertJsonPath('data.bulk_designer.id', $colleague->id)->assertJsonCount(2, 'data.assignment.history');

        $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/bulk-jobs/{$this->job->id}")->assertNotFound();
        submitBulk($this, $this->job, $colleague)->assertOk();
        assignInternal($this, $this->job, $this->bulkDesigner, extra: ['reason' => 'Back'])->assertStatus(409); // in review
    });

    it('refuses assignment while the advance is short', function () {
        $this->job->order->forceFill(['verified_paid_minor' => 0])->save();
        assignInternal($this, $this->job)->assertStatus(409)->assertJsonPath('code', 'advance_short');
    });
});

describe('concurrency', function () {
    it('rejects stale versions so concurrent status updates cannot both win', function () {
        assignInternal($this, $this->job)->assertOk();
        $stale = jobVersion($this->job);

        // The designer changes the job while the Operation Admin's screen is still open.
        enterMeasurements($this, $this->job)->assertOk();
        $colleague = $this->userWithRole(Roles::BULK_DESIGNER);
        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$this->job->id}/assignment", [
            'version' => $stale, 'mode' => 'internal', 'bulk_designer_id' => $colleague->id, 'reason' => 'Workload',
        ])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        expect($this->job->fresh()->bulk_designer_id)->toBe($this->bulkDesigner->id);

        submitBulk($this, $this->job)->assertOk();
        $current = jobVersion($this->job);
        $this->signedInAs($this->owner)->postJson("/api/v1/bulk-jobs/{$this->job->id}/approve", ['version' => $current])->assertOk();
        $this->signedInAs($this->owner)->postJson("/api/v1/bulk-jobs/{$this->job->id}/corrections", ['version' => $current, 'reason' => 'Too late'])->assertStatus(409);
        expect(BulkReview::query()->count())->toBe(1);

        // Two decisions racing past the policy check: the second one fails under the row lock.
        expect(fn () => app(ReviewBulk::class)->approve($this->owner, BulkJob::query()->findOrFail($this->job->id), jobVersion($this->job), null))
            ->toThrow(ApiException::class, 'This bulk is not waiting for review.')
            ->and(fn () => app(ReviewBulk::class)->requestCorrection($this->owner, BulkJob::query()->findOrFail($this->job->id), $current, 'Stale'))
            ->toThrow(ApiException::class, 'The bulk job was changed by someone else. Reload it and try again.');
        expect(BulkReview::query()->count())->toBe(1);
    });
});

describe('setup', function () {
    it('configures measurement fields, versioned checklist templates and external producers', function () {
        $this->signedInAs($this->bulkDesigner)->getJson('/api/v1/bulk-setup')->assertForbidden();
        $setup = $this->signedInAs($this->ops)->getJson('/api/v1/bulk-setup')->assertOk()->json('data');
        $bulkMeter = collect($setup['measurement_fields'])->firstWhere('key', 'bulk_meter');
        expect($bulkMeter['is_system'])->toBeTrue();

        $this->signedInAs($this->ops)->patchJson("/api/v1/bulk-setup/measurement-fields/{$bulkMeter['id']}", ['is_active' => false])->assertStatus(409);
        $this->signedInAs($this->ops)->postJson('/api/v1/bulk-setup/measurement-fields', ['key' => 'ink_litres', 'label' => 'Ink', 'unit' => 'l', 'min_value' => 5, 'max_value' => 1, 'is_required' => true])
            ->assertStatus(422)->assertJsonValidationErrors('min_value');
        $this->signedInAs($this->ops)->postJson('/api/v1/bulk-setup/measurement-fields', ['key' => 'ink_litres', 'label' => 'Ink', 'unit' => 'l', 'decimals' => 1, 'is_required' => true])->assertCreated();

        $this->signedInAs($this->ops)->postJson('/api/v1/bulk-setup/checklist-templates', ['stage' => 'bulk_preparation', 'name' => 'Internal bulk checklist', 'items' => [['label' => 'Only item', 'is_required' => true]]])
            ->assertCreated()->assertJsonPath('data.version_no', 2);
        expect(ChecklistTemplate::query()->where('stage', 'bulk_preparation')->where('is_active', true)->count())->toBe(1);

        $this->signedInAs($this->ops)->postJson('/api/v1/bulk-setup/suppliers', ['name' => 'Stitch Co', 'service_type' => 'full', 'phone' => '0771234567'])->assertCreated();

        // A newly assigned job uses the new template and the new required field.
        assignInternal($this, $this->job)->assertOk()->assertJsonCount(1, 'data.checklist.items')
            ->assertJsonPath('data.checklist.template', 'Internal bulk checklist v2');
        enterMeasurements($this, $this->job, ['ink_litres' => '2.55'])->assertStatus(422);
        expect(bulkJob($this, $this->job)['completion']['missing']['measurements'])->toContain('Ink is required.');
        $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs/assignees')->assertOk()->assertJsonCount(2, 'data.suppliers');
    });
});
