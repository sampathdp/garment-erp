<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\Cutting\Actions\OpenCuttingJob;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobAssignment;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    cuttingWorld($this);
});

describe('cutting job creation', function () {
    it('opens exactly one cutting job when the gate pass is recorded after printing', function () {
        Notification::fake();
        $otherOps = $this->userWithRole(Roles::OPERATION_ADMIN);
        $job = cuttingReadyJob($this);
        $order = Order::query()->findOrFail($job->order_id);

        expect($job->status->value)->toBe('pending_assignment')
            ->and($job->planned_quantity)->toBe(10)
            ->and($order->status->value)->toBe('cutting')
            ->and($job->quantities()->orderBy('sort_order')->get(['size_label', 'expected_quantity'])->toArray())
            ->toBe([['size_label' => 'S', 'expected_quantity' => 4], ['size_label' => 'M', 'expected_quantity' => 6]]);
        // The Operation Admin who recorded the gate pass is the actor and is never notified.
        Notification::assertSentTo($otherOps, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'cutting.job_ready');
        Notification::assertNotSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'cutting.job_ready');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cutting.job_opened']);

        // A second gate pass never duplicates the cutting task.
        $print = PrintJob::query()->findOrFail($job->print_job_id);
        recordGatePass($this, $print, ['gate_pass_no' => 'GP-2026-0043'])->assertOk();
        expect(CuttingJob::query()->count())->toBe(1);
        DB::transaction(fn () => app(OpenCuttingJob::class)->handle($print->fresh(), $order->fresh(), $this->ops));
        expect(CuttingJob::query()->count())->toBe(1);
    });

    it('is never available before printing is completed', function () {
        $print = approvedSampleJob($this);
        $order = Order::query()->findOrFail($print->order_id);

        expect(fn () => DB::transaction(fn () => app(OpenCuttingJob::class)->handle($print->fresh(), $order, $this->ops)))
            ->toThrow(ApiException::class, 'available only after printing is completed');
        expect(CuttingJob::query()->count())->toBe(0);
        $this->signedInAs($this->ops)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('shows the cutting information on the dashboard', function () {
        $job = cuttingReadyJob($this);

        $row = $this->signedInAs($this->ops)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        expect($row['job_no'])->toBe(Order::query()->value('order_no'))
            ->and($row['branch'])->toBe('CMB')
            ->and($row['page']['code'])->toBe('JRSY')
            ->and($row['material'])->toBe('Wet look')
            ->and($row['product_category'])->toBe('Jersey')
            ->and($row['quantity'])->toBe(10)
            ->and($row['list']['total_quantity'])->toBe(10)
            ->and($row['printing']['printed_quantity'])->toBe(10)
            ->and($row['printing']['gate_passes'][0]['gate_pass_no'])->toBe('GP-2026-0042')
            ->and($row['status']['value'])->toBe('pending_assignment')
            ->and($row['design_preview_url'])->not->toBeNull();

        $this->signedInAs($this->ops)->get($row['design_preview_url'])->assertOk();
        $this->signedInAs($this->ops)->getJson("/api/v1/cutting-jobs/{$job->id}/job-card")->assertOk()->assertJsonPath('data.job_no', $row['job_no']);
        $this->signedInAs($this->ops)->getJson("/api/v1/cutting-jobs/{$job->id}/name-list")->assertOk()->assertJsonCount(10, 'data.items');
    });

    it('lists without N+1 queries and hides other pages and unassigned jobs', function () {
        $first = cuttingReadyJob($this, ['due_date' => '2026-11-21']);
        $this->signedInAs($this->ops)->getJson('/api/v1/cutting-jobs')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(1, 'data');
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        cuttingReadyJob($this, ['due_date' => '2026-11-22']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(2, 'data');
        expect(count(DB::getQueryLog()))->toBe($one);
        DB::disableQueryLog();

        // Cutting staff see only the jobs assigned to them.
        $this->signedInAs($this->cutter)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(0, 'data');
        assignCutter($this, $first)->assertOk();
        $this->signedInAs($this->cutter)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($this->otherCutter)->getJson("/api/v1/cutting-jobs/{$first->id}")->assertNotFound();

        $this->signedInAs($this->ownerB)->getJson('/api/v1/cutting-jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->ownerB)->getJson("/api/v1/cutting-jobs/{$first->id}")->assertNotFound();
        $this->signedInAs($this->second)->getJson('/api/v1/cutting-jobs')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/cutting-jobs')->assertUnauthorized();
    });
});

describe('cutting assignment', function () {
    it('lets the Operation Admin assign a cutter and records the assignment', function () {
        Notification::fake();
        $job = cuttingReadyJob($this);

        $data = assignCutter($this, $job)->assertOk()->json('data');
        expect($data['status']['value'])->toBe('assigned')
            ->and($data['cutter']['name'])->toBe($this->cutter->name)
            ->and($data['expected_completion_date'])->toBe('2026-11-12')
            ->and($data['assigned_at'])->not->toBeNull()
            ->and($data['assignment']['assigned_by'])->toBe($this->ops->name)
            ->and($data['assignment']['history'])->toHaveCount(1)
            ->and($data['checklist']['items'])->not->toBeEmpty()
            ->and($data['quality_checks']['items'])->not->toBeEmpty();
        Notification::assertSentTo($this->cutter, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'cutting.assigned');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cutting.assigned']);

        // The assigned cutter can open it; the abilities follow the policy.
        $mine = cuttingJob($this, $job, $this->cutter);
        expect($mine['abilities']['record_work'])->toBeTrue()->and($mine['abilities']['assign'])->toBeFalse()->and($mine['abilities']['complete'])->toBeFalse();
    });

    it('requires a reason to reassign and keeps the history', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();

        assignCutter($this, $job, $this->otherCutter)->assertUnprocessable()->assertJsonValidationErrors('reason');
        assignCutter($this, $job, $this->otherCutter, extra: ['reason' => 'First cutter on leave'])->assertOk()->assertJsonPath('data.cutter.name', $this->otherCutter->name);

        expect(CuttingJobAssignment::query()->where('cutting_job_id', $job->id)->count())->toBe(2);
        $this->signedInAs($this->cutter)->getJson("/api/v1/cutting-jobs/{$job->id}")->assertNotFound();
    });

    it('rejects ineligible cutters, bad input, stale versions and unauthorized users', function () {
        $job = cuttingReadyJob($this);

        assignCutter($this, $job, $this->bulkDesigner)->assertUnprocessable()->assertJsonValidationErrors('cutter_id');
        assignCutter($this, $job, extra: ['expected_completion_date' => '2026-11-01'])->assertUnprocessable()->assertJsonValidationErrors('expected_completion_date');
        assignCutter($this, $job, extra: ['version' => 99])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        assignCutter($this, $job, as: $this->cutter)->assertNotFound();
        assignCutter($this, $job, as: $this->owner)->assertForbidden();
        assignCutter($this, $job, as: $this->ownerB)->assertNotFound();
        $this->asGuest()->postJson("/api/v1/cutting-jobs/{$job->id}/assignment", [])->assertUnauthorized();
    });
});

describe('cutting checklist', function () {
    it('records completion, responsible user, date and notes on assigned jobs only', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        [$first] = cutChecklistItems($job, ChecklistStage::Cutting);

        $item = $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/{$first}", [
            'is_completed' => true, 'notes' => 'Counted twice', 'responsible_user_id' => $this->otherCutter->id,
        ])->assertOk()->json('data.checklist.items.0');
        expect($item['is_completed'])->toBeTrue()
            ->and($item['notes'])->toBe('Counted twice')
            ->and($item['responsible'])->toBe($this->otherCutter->name)
            ->and($item['completed_by'])->toBe($this->cutter->name)
            ->and($item['completed_at'])->not->toBeNull();
        expect(CuttingJob::query()->findOrFail($job->id)->status->value)->toBe('in_progress');

        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/{$first}", ['responsible_user_id' => $this->owner->id])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
        $this->signedInAs($this->otherCutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/{$first}", ['is_completed' => false])->assertNotFound();
        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/999999", ['is_completed' => true])->assertNotFound();
    });

    it('cannot be updated before a cutter is assigned', function () {
        $job = cuttingReadyJob($this);
        $this->signedInAs($this->ops)->putJson("/api/v1/cutting-jobs/{$job->id}/quantities", ['version' => cutVersion($job), 'quantities' => [['id' => 1, 'cut' => 1]]])
            ->assertStatus(409);
    });

    it('needs every mandatory task before the client confirmation, then locks the checklist', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();

        confirmCuttingChecklist($this, $job)->assertStatus(409)->assertJsonPath('code', 'checklist_incomplete');
        completeCuttingChecklist($this, $job);
        confirmCuttingChecklist($this, $job, $this->ops)->assertForbidden();
        confirmCuttingChecklist($this, $job, $this->ownerB)->assertNotFound();
        $data = confirmCuttingChecklist($this, $job)->assertOk()->json('data.checklist.client_confirmation');
        expect($data['recorded_by'])->toBe($this->owner->name)->and($data['channel'])->toBe('whatsapp');

        [$first] = cutChecklistItems($job, ChecklistStage::Cutting);
        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/{$first}", ['is_completed' => false])
            ->assertStatus(409)->assertJsonPath('code', 'checklist_confirmed');
        confirmCuttingChecklist($this, $job)->assertStatus(409);
    });
});

describe('quantity checking', function () {
    it('records cut quantities per size and shows missing items', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();

        $data = recordCut($this, $job, ['S' => 4, 'M' => 4])->assertOk()->json('data');
        expect($data['totals'])->toBe(['expected' => 10, 'cut' => 8, 'missing' => 2, 'defective' => 0, 'good' => 8])
            ->and(collect($data['quantities'])->firstWhere('size', 'M')['missing'])->toBe(2)
            ->and($data['completion']['blockers']['quantities'])->toBe(['Size M: 2 of 6 not cut yet.']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cutting.quantities_recorded']);
    });

    it('prevents negative quantities, over-cutting and foreign rows', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        $rows = $job->quantities()->get()->keyBy('size_label');

        recordCut($this, $job, ['S' => -1])->assertUnprocessable();
        recordCut($this, $job, ['S' => 5])->assertUnprocessable()->assertJsonValidationErrors("quantities.{$rows['S']->id}");
        $this->signedInAs($this->cutter)->putJson("/api/v1/cutting-jobs/{$job->id}/quantities", ['version' => cutVersion($job), 'quantities' => [['id' => 999999, 'cut' => 1]]])
            ->assertUnprocessable();
        $this->signedInAs($this->cutter)->putJson("/api/v1/cutting-jobs/{$job->id}/quantities", ['version' => cutVersion($job) - 1, 'quantities' => [['id' => $rows['S']->id, 'cut' => 1]]])
            ->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        recordCut($this, $job, as: $this->otherCutter)->assertNotFound();
        expect(CuttingJob::query()->findOrFail($job->id)->cut_quantity)->toBe(0);
    });

    it('never lets the total cut fall below the defective pieces already reported', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        recordCut($this, $job, ['S' => 4, 'M' => 2])->assertOk();
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 5])->assertCreated();

        recordCut($this, $job, ['S' => 2, 'M' => 2])->assertUnprocessable()->assertJsonPath('code', 'invalid_totals');
    });
});

describe('damage and missing-piece reporting', function () {
    it('reports damaged pieces, incorrect cuts and missing parts within the cut quantity', function () {
        Notification::fake();
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        recordCut($this, $job, ['S' => 4, 'M' => 6])->assertOk();

        $issue = reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 2, 'size' => 'M', 'image' => pngFile('tear.png')])
            ->assertCreated()->json('data');
        expect($issue['category']['value'])->toBe('damaged_pieces')
            ->and($issue['status']['value'])->toBe('open')
            ->and($issue['is_blocking'])->toBeTrue()
            ->and($issue['reported_by'])->toBe($this->cutter->name)
            ->and($issue['has_image'])->toBeTrue();
        $this->signedInAs($this->cutter)->get($issue['image_url'])->assertOk();
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.reported');
        Notification::assertNotSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.reported');

        reportCuttingIssue($this, $job, ['category' => 'incorrect_cut', 'quantity_affected' => 3])->assertCreated();
        reportCuttingIssue($this, $job, ['category' => 'missing_parts', 'quantity_affected' => 1])->assertUnprocessable()->assertJsonValidationErrors('part');
        reportCuttingIssue($this, $job, ['category' => 'missing_parts', 'quantity_affected' => 1, 'part' => 'sleeve'])->assertCreated()->assertJsonPath('data.part.value', 'sleeve');
        // 2 + 3 + 1 = 6 of 10 cut pieces reported; 5 more is too many.
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 5])->assertUnprocessable()->assertJsonValidationErrors('quantity_affected');
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 7, 'size' => 'M'])->assertUnprocessable();
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces'])->assertUnprocessable()->assertJsonValidationErrors('quantity_affected');
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 0])->assertUnprocessable();

        $totals = cuttingJob($this, $job)['totals'];
        expect($totals['defective'])->toBe(6)->and($totals['good'])->toBe(4);
    });

    it('reports missing items only up to the pieces not cut', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        recordCut($this, $job, ['S' => 4, 'M' => 4])->assertOk();

        reportCuttingIssue($this, $job, ['category' => 'missing_items', 'quantity_affected' => 3])->assertUnprocessable();
        reportCuttingIssue($this, $job, ['category' => 'missing_items', 'quantity_affected' => 2, 'size' => 'M'])->assertCreated();
        reportCuttingIssue($this, $job, ['category' => 'missing_items', 'quantity_affected' => 1, 'size' => 'XL'])->assertUnprocessable()->assertJsonValidationErrors('size_label');
    });

    it('notifies the Bulk Designer for bulk-related issues', function () {
        Notification::fake();
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();

        reportCuttingIssue($this, $job, ['category' => 'bulk_error', 'description' => 'Number 7 is misspelt on the bulk layout'])->assertCreated();
        Notification::assertSentTo($this->bulkDesigner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.reported');
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.reported');
    });

    it('refuses reports from users who cannot work on the job', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();

        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 1], $this->otherCutter)->assertNotFound();
        reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 1], $this->ownerB)->assertNotFound();
        reportCuttingIssue($this, $job, ['category' => 'nonsense'])->assertUnprocessable();
    });
});

describe('quality checks', function () {
    it('needs a note for failures and blocks completion until mandatory checks pass', function () {
        $job = readyToCompleteCutting($this);
        [$first] = cutChecklistItems($job, ChecklistStage::QualityControl);

        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'fail'])
            ->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'fail', 'notes' => 'Frayed edges on 2 panels'])->assertOk();

        completeCutting($this, $job)->assertStatus(409)->assertJsonPath('code', 'cutting_incomplete')
            ->assertJsonPath('errors.quality_checks.0', 'Cut edges clean, no fraying: failed');

        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'na', 'notes' => 'Not relevant'])->assertOk();
        completeCutting($this, $job)->assertStatus(409);

        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'pass'])->assertOk();
        completeCutting($this, $job)->assertOk();
    });

    it('lets only qc.inspect holders record checks', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        [$first] = cutChecklistItems($job, ChecklistStage::QualityControl);

        $this->signedInAs($this->owner)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'pass'])->assertForbidden();
        $this->signedInAs($this->ops)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$first}", ['result' => 'pass'])->assertOk();
    });
});

describe('cutting completion', function () {
    it('completes cutting, records the responsible users and moves the job to sewing', function () {
        Notification::fake();
        $job = readyToCompleteCutting($this);
        expect(cuttingJob($this, $job)['completion']['blockers'])->toBe([]);

        completeCutting($this, $job, $this->cutter)->assertForbidden();
        completeCutting($this, $job, $this->owner)->assertForbidden();
        completeCutting($this, $job, $this->ownerB)->assertNotFound();

        $data = completeCutting($this, $job)->assertOk()->json('data');
        expect($data['status']['value'])->toBe('completed')
            ->and($data['completion']['completed_by'])->toBe($this->ops->name)
            ->and($data['order']['status']['value'])->toBe('sewing')
            ->and($data['cutter']['name'])->toBe($this->cutter->name);
        $audit = DB::table('audit_logs')->where('action', 'cutting.completed')->sole();
        expect(json_decode((string) $audit->changes, true)['after']['responsible']['cutter_id'])->toBe($this->cutter->id);
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'cutting.completed');

        completeCutting($this, $job)->assertStatus(409);
        recordCut($this, $job)->assertStatus(409);
    });

    it('is blocked by unresolved blocking issues until they are resolved', function () {
        $job = readyToCompleteCutting($this);
        $issueId = reportCuttingIssue($this, $job, ['category' => 'damaged_pieces', 'quantity_affected' => 1])->assertCreated()->json('data.id');

        completeCutting($this, $job)->assertStatus(409)->assertJsonPath('code', 'cutting_incomplete')->assertJsonStructure(['errors' => ['issues']]);
        moveIssue($this, $issueId, 'under_investigation')->assertOk();
        completeCutting($this, $job)->assertStatus(409);
        moveIssue($this, $issueId, 'resolved', ['resolution' => 'Panel recut'])->assertOk();
        completeCutting($this, $job)->assertOk();
    });

    it('is not blocked by a non-blocking issue', function () {
        $job = readyToCompleteCutting($this);
        $this->signedInAs($this->ops)->post("/api/v1/cutting-jobs/{$job->id}/issues", ['category' => 'machine', 'description' => 'Cutter blade slow'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.is_blocking', false);

        completeCutting($this, $job)->assertOk();
    });

    it('lists every missing requirement', function () {
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        recordCut($this, $job, ['S' => 1])->assertOk();

        $errors = completeCutting($this, $job)->assertStatus(409)->json('errors');
        expect(array_keys($errors))->toBe(['quantities', 'checklist', 'quality_checks'])
            ->and($errors['checklist'])->toContain('The client confirmation of the cutting checklist is not recorded.');
    });
});
