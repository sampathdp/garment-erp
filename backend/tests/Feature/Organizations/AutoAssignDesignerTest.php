<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Support\Roles;
use App\Modules\Organizations\Support\WorkflowSettings;

beforeEach(fn () => bulkWorld($this));

it('automatically assigns the least-loaded free designer when auto_assign_designer is enabled', function () {
    $designerA = $this->designer;
    $designerB = $this->userWithRole(Roles::DESIGN_DESIGNER);

    app(WorkflowSettings::class)->update($this->admin, [
        WorkflowSettings::AUTO_ASSIGN_DESIGNER => true,
        WorkflowSettings::DEFAULT_DESIGNER => $designerA->id,
    ]);

    // First task: both have 0 active tasks, default designerA wins tie-break
    $order1 = approvedOrder($this);
    $task1 = submitJobDetails($this, $order1);
    expect($task1->designer_id)->toBe($designerA->id);

    // Second task: designerA has 1 active task, designerB has 0 active tasks -> designerB MUST be chosen
    $order2 = approvedOrder($this);
    $task2 = submitJobDetails($this, $order2);
    expect($task2->designer_id)->toBe($designerB->id);

    // Third task: both have 1 active task -> designerA wins tie-break
    $order3 = approvedOrder($this);
    $task3 = submitJobDetails($this, $order3);
    expect($task3->designer_id)->toBe($designerA->id);
});

it('automatically assigns the least-loaded bulk designer when auto_assign_bulk_designer is enabled', function () {
    $bulkA = $this->bulkDesigner;
    $bulkB = $this->userWithRole(Roles::BULK_DESIGNER);

    app(WorkflowSettings::class)->update($this->admin, [
        WorkflowSettings::AUTO_ASSIGN_BULK_DESIGNER => true,
        WorkflowSettings::DEFAULT_BULK_DESIGNER => $bulkA->id,
    ]);

    // Give bulkA an active assigned bulk job
    $job1 = readyBulkJob($this);
    assignInternal($this, $job1, $bulkA)->assertOk();

    // Now bulkA has 1 active job, bulkB has 0 jobs.
    // When a new bulk job becomes ready, it must auto-assign to bulkB
    $order2 = approvedOrder($this);
    $task2 = submitJobDetails($this, $order2);
    fillList($this, $order2);
    confirmList($this, $order2)->assertOk();
    completeDesign($this, $task2);

    $job2 = BulkJob::query()->where('order_id', $order2->id)->sole();
    expect($job2->bulk_designer_id)->toBe($bulkB->id);
});

it('returns active workloads and free status on designers and assignees endpoints', function () {
    $bulkA = $this->bulkDesigner;
    $bulkB = $this->userWithRole(Roles::BULK_DESIGNER);

    $job1 = readyBulkJob($this);
    assignInternal($this, $job1, $bulkA)->assertOk();

    $response = $this->signedInAs($this->ops)->getJson('/api/v1/bulk-jobs/assignees')->assertOk();
    $bulkDesigners = collect($response->json('data.designers'));

    $aData = $bulkDesigners->firstWhere('id', $bulkA->id);
    $bData = $bulkDesigners->firstWhere('id', $bulkB->id);

    expect($aData)->not->toBeNull()->and($aData['active_jobs'])->toBe(1);
    expect($bData)->not->toBeNull()->and($bData['active_jobs'])->toBe(0)->and($bData['is_free'])->toBeTrue();

    $designResponse = $this->signedInAs($this->admin)->getJson('/api/v1/design-tasks/designers')->assertOk();
    $designers = collect($designResponse->json('data.items'));
    $dData = $designers->firstWhere('id', $this->designer->id);
    expect($dData)->toHaveKeys(['id', 'name', 'active_tasks', 'is_free', 'has_access']);
});

it('allows the Super Admin to toggle auto-assignment in workflow settings', function () {
    $this->signedInAs($this->admin)->putJson('/api/v1/settings/workflow', [
        'default_designer_id' => $this->designer->id,
        'default_bulk_designer_id' => $this->bulkDesigner->id,
        'auto_assign_designer' => true,
        'auto_assign_bulk_designer' => true,
        'max_correction_rounds' => 3,
    ])->assertOk()
        ->assertJsonPath('data.auto_assign_designer', true)
        ->assertJsonPath('data.auto_assign_bulk_designer', true);

    $settings = app(WorkflowSettings::class);
    expect($settings->autoAssignDesigner())->toBeTrue();
    expect($settings->autoAssignBulkDesigner())->toBeTrue();

    $this->signedInAs($this->admin)->putJson('/api/v1/settings/workflow', [
        'default_designer_id' => $this->designer->id,
        'default_bulk_designer_id' => $this->bulkDesigner->id,
        'auto_assign_designer' => false,
        'auto_assign_bulk_designer' => false,
        'max_correction_rounds' => 3,
    ])->assertOk()
        ->assertJsonPath('data.auto_assign_designer', false)
        ->assertJsonPath('data.auto_assign_bulk_designer', false);

    expect($settings->autoAssignDesigner())->toBeFalse();
    expect($settings->autoAssignBulkDesigner())->toBeFalse();
});

