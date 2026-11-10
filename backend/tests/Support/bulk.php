<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 05 fixtures on top of designWorld(): an external producer, and helpers that drive a
 * bulk job through the API exactly as the screens do.
 */
function bulkWorld(TestCase $test): void
{
    designWorld($test);
    $test->supplier = Supplier::query()->create(['name' => 'Print House Ltd', 'service_type' => 'printing', 'is_active' => true]);
}

/** Approved order → job details → confirmed list → completed design: the bulk job exists (pending assignment). */
function readyBulkJob(TestCase $test, array $overrides = []): BulkJob
{
    $order = approvedOrder($test, $overrides);
    $task = submitJobDetails($test, $order);
    fillList($test, $order);
    confirmList($test, $order)->assertOk();
    completeDesign($test, $task);

    return BulkJob::query()->where('order_id', $order->id)->sole();
}

function jobVersion(BulkJob $job): int
{
    return (int) BulkJob::query()->whereKey($job->id)->value('version');
}

function bulkJob(TestCase $test, BulkJob $job, ?User $as = null): array
{
    return $test->signedInAs($as ?? $test->ops)->getJson("/api/v1/bulk-jobs/{$job->id}")->assertOk()->json('data');
}

function assignInternal(TestCase $test, BulkJob $job, ?User $designer = null, ?User $as = null, array $extra = []): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/bulk-jobs/{$job->id}/assignment", [
        'version' => jobVersion($job), 'mode' => 'internal', 'bulk_designer_id' => ($designer ?? $test->bulkDesigner)->id, 'notes' => 'Priority club order', ...$extra,
    ]);
}

function assignExternal(TestCase $test, BulkJob $job, ?Supplier $supplier = null, ?User $as = null, array $extra = []): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/bulk-jobs/{$job->id}/assignment", [
        'version' => jobVersion($job), 'mode' => 'external', 'supplier_id' => ($supplier ?? $test->supplier)->id, ...$extra,
    ]);
}

function enterMeasurements(TestCase $test, BulkJob $job, array $values = ['bulk_meter' => '12.5', 'patch_meter' => '3.25'], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->bulkDesigner)->putJson("/api/v1/bulk-jobs/{$job->id}/measurements", ['values' => $values]);
}

function completeChecklist(TestCase $test, BulkJob $job, ?User $as = null): void
{
    $items = ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('bulk_job_id', $job->id)->whereNull('superseded_at'))->pluck('id');
    expect($items)->not->toBeEmpty();
    foreach ($items as $id) {
        $test->signedInAs($as ?? $test->bulkDesigner)->patchJson("/api/v1/bulk-jobs/{$job->id}/checklist/items/{$id}", ['is_completed' => true, 'notes' => 'Checked'])->assertOk();
    }
}

function confirmChecklistWithClient(TestCase $test, BulkJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/bulk-jobs/{$job->id}/checklist/client-confirmation", ['channel' => 'whatsapp', 'comment' => 'Customer confirmed the checklist']);
}

function submitBulk(TestCase $test, BulkJob $job, ?User $as = null, array $extra = []): TestResponse
{
    return $test->signedInAs($as ?? $test->bulkDesigner)->post("/api/v1/bulk-jobs/{$job->id}/versions", [
        'version' => jobVersion($job), 'preview' => pngFile('bulk.png'), 'files' => [pdfFile('bulk-layout.pdf')], 'notes' => 'Bulk layout', ...$extra,
    ], ['Accept' => 'application/json']);
}

function approveBulk(TestCase $test, BulkJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/bulk-jobs/{$job->id}/approve", ['version' => jobVersion($job), 'comment' => 'Looks good']);
}

function requestBulkCorrection(TestCase $test, BulkJob $job, string $reason = 'Number 7 is too small', ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/bulk-jobs/{$job->id}/corrections", ['version' => jobVersion($job), 'reason' => $reason]);
}

function wetransferLink(): string
{
    return 'https://wetransfer.com/downloads/4f1c2a/9b8d7e';
}

function submitFinalBulk(TestCase $test, BulkJob $job, array $overrides = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->bulkDesigner)->post("/api/v1/bulk-jobs/{$job->id}/final", [
        'version' => jobVersion($job), 'files' => [pdfFile('bulk-final.pdf')], 'transfer_url' => wetransferLink(), 'notes' => 'Final bulk', ...$overrides,
    ], ['Accept' => 'application/json']);
}

/** Internal path up to an approved bulk with everything except the final submission. */
function approvedInternalBulk(TestCase $test, BulkJob $job): void
{
    assignInternal($test, $job)->assertOk();
    enterMeasurements($test, $job)->assertOk();
    completeChecklist($test, $job);
    submitBulk($test, $job)->assertOk();
    approveBulk($test, $job)->assertOk();
    confirmChecklistWithClient($test, $job)->assertOk();
}
