<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Organizations\Support\WorkflowSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Direct-to-bulk mode (WorkflowSettings::DESIGN_DIRECT_TO_BULK, default on): no design review. */
beforeEach(function () {
    designWorld($this);
    app(WorkflowSettings::class)->update($this->admin, [WorkflowSettings::DESIGN_DIRECT_TO_BULK => true]);
});

function directUpload(TestCase $test, DesignTask $task, array $payload = ['files' => null, 'external_url' => 'https://drive.google.com/file/d/final123/view'])
{
    $payload['files'] ??= [pdfFile()];

    return $test->signedInAs($test->designer)->post("/api/v1/design-tasks/{$task->id}/versions", ['preview' => pngFile(), ...$payload, 'notes' => 'Final artwork'], ['Accept' => 'application/json']);
}

it('is on by default', function () {
    DB::table('app_settings')->where('key', WorkflowSettings::DESIGN_DIRECT_TO_BULK)->delete();

    expect(app(WorkflowSettings::class)->designDirectToBulk())->toBeTrue();
});

it('completes the design on upload and sends it straight to bulk, without review', function () {
    $order = approvedOrder($this);
    $task = submitJobDetails($this, $order);
    fillList($this, $order);
    confirmList($this, $order)->assertOk();

    directUpload($this, $task)->assertOk()
        ->assertJsonPath('data.status.value', DesignTaskStatus::Completed->value)
        ->assertJsonPath('data.direct_to_bulk', true)
        ->assertJsonPath('data.abilities.approve', false)
        ->assertJsonPath('data.abilities.submit_final', false);

    $task->refresh();
    $job = BulkJob::query()->where('order_id', $order->id)->sole();
    expect($task->getAttribute('final_version_id'))->not->toBeNull()
        ->and($task->getAttribute('approved_version_id'))->toBe($task->getAttribute('final_version_id'))
        ->and($order->fresh()->status->value)->toBe('design_approved')
        ->and($job->status)->toBe(BulkJobStatus::PendingAssignment)
        ->and(DB::table('design_reviews')->whereIn('design_version_id', DB::table('design_versions')->where('design_task_id', $task->id)->select('id'))->count())->toBe(0);
    $this->assertDatabaseHas('order_status_histories', ['order_id' => $order->id, 'from_status' => 'design_in_progress', 'to_status' => 'design_approved']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'designs.completed', 'subject_id' => $task->id]);

    // The review endpoints have nothing to act on.
    $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp'])->assertConflict();
    $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => 'Change font', 'channel' => 'whatsapp'])->assertConflict();
});

it('still waits for the locked list and the advance before creating the bulk job', function () {
    $order = approvedOrder($this);
    $task = submitJobDetails($this, $order);

    directUpload($this, $task)->assertOk()->assertJsonPath('data.status.value', 'completed');
    expect(BulkJob::query()->where('order_id', $order->id)->exists())->toBeFalse();

    fillList($this, $order);
    confirmList($this, $order)->assertOk();
    expect(BulkJob::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

it('needs the PDF/CDR file(s) or the Drive link', function () {
    $task = submitJobDetails($this, approvedOrder($this));

    $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/versions", ['preview' => pngFile()], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('files');
    directUpload($this, $task, ['files' => [pdfFile()], 'external_url' => null])->assertOk();
});

it('lets the designer replace the design until bulk work starts', function () {
    $order = approvedOrder($this);
    $task = submitJobDetails($this, $order);
    fillList($this, $order);
    confirmList($this, $order)->assertOk();
    directUpload($this, $task)->assertOk();
    $job = BulkJob::query()->where('order_id', $order->id)->sole();
    $first = $task->fresh()->getAttribute('final_version_id');

    assignInternal($this, $job)->assertOk(); // assigned, not started
    directUpload($this, $task->fresh())->assertOk()->assertJsonPath('data.abilities.upload_version', true);
    $second = $task->fresh()->getAttribute('final_version_id');
    expect($second)->not->toBe($first)
        ->and($task->fresh()->getAttribute('approved_version_id'))->toBe($second);
    $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->bulkDesigner->id]);

    enterMeasurements($this, $job->fresh())->assertOk(); // bulk work started
    directUpload($this, $task->fresh())->assertConflict();
    $this->signedInAs($this->designer)->getJson("/api/v1/design-tasks/{$task->id}")->assertJsonPath('data.abilities.upload_version', false);
});

it('can be switched off in the workflow settings (review flow again)', function () {
    $this->signedInAs($this->admin)->putJson('/api/v1/settings/workflow', [
        'default_designer_id' => $this->designer->id, 'default_bulk_designer_id' => $this->bulkDesigner->id,
        'max_correction_rounds' => 3, 'design_direct_to_bulk' => false,
    ])->assertOk()->assertJsonPath('data.design_direct_to_bulk', false);

    $task = submitJobDetails($this, approvedOrder($this));
    submitVersion($this, $task)->assertOk()->assertJsonPath('data.status.value', 'in_review');
});
