<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 07 fixtures on top of bulkWorld(): a printed job whose gate pass is recorded (the
 * cutting job exists, pending assignment), and helpers that drive the cutting workflow and the
 * issue register through the API exactly as the screens do. The confirmed list has 4 × S and 6 × M.
 */
function cuttingWorld(TestCase $test): void
{
    bulkWorld($test);
    $test->cutter = $test->userWithRole(Roles::CUTTING_STAFF);
    $test->otherCutter = $test->userWithRole(Roles::CUTTING_STAFF);
}

/** Printing completed + gate pass recorded → the cutting job (pending assignment). */
function cuttingReadyJob(TestCase $test, array $overrides = []): CuttingJob
{
    $print = printReadyJob($test, $overrides);
    sendForPrinting($test, $print)->assertOk();
    uploadSample($test, $print)->assertOk();
    approveSample($test, $print)->assertOk();
    startPrinting($test, $print)->assertOk();
    completePrinting($test, $print)->assertOk();
    recordGatePass($test, $print)->assertOk();

    return CuttingJob::query()->where('print_job_id', $print->id)->sole();
}

function cutVersion(CuttingJob $job): int
{
    return (int) CuttingJob::query()->whereKey($job->id)->value('version');
}

function cuttingJob(TestCase $test, CuttingJob $job, ?User $as = null): array
{
    return $test->signedInAs($as ?? $test->ops)->getJson("/api/v1/cutting-jobs/{$job->id}")->assertOk()->json('data');
}

function assignCutter(TestCase $test, CuttingJob $job, ?User $cutter = null, ?User $as = null, array $extra = []): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/cutting-jobs/{$job->id}/assignment", [
        'version' => cutVersion($job), 'cutter_id' => ($cutter ?? $test->cutter)->id, 'expected_completion_date' => '2026-11-12', 'notes' => 'Cut first thing', ...$extra,
    ]);
}

/** @param  array<string, int>  $bySize  size label => cut quantity */
function recordCut(TestCase $test, CuttingJob $job, array $bySize = ['S' => 4, 'M' => 6], ?User $as = null): TestResponse
{
    $rows = CuttingJobQuantity::query()->where('cutting_job_id', $job->id)->get()->keyBy('size_label');

    return $test->signedInAs($as ?? $test->cutter)->putJson("/api/v1/cutting-jobs/{$job->id}/quantities", [
        'version' => cutVersion($job),
        'quantities' => collect($bySize)->map(fn (int $cut, string $size) => ['id' => $rows[$size]->id, 'cut' => $cut])->values()->all(),
    ]);
}

/** @return list<int> item ids of the job's checklist for the stage */
function cutChecklistItems(CuttingJob $job, ChecklistStage $stage): array
{
    return ChecklistRunItem::query()->whereHas('run', fn ($q) => $q->where('cutting_job_id', $job->id)->where('stage', $stage->value)->whereNull('superseded_at'))
        ->orderBy('sort_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
}

function completeCuttingChecklist(TestCase $test, CuttingJob $job, ?User $as = null): void
{
    $items = cutChecklistItems($job, ChecklistStage::Cutting);
    expect($items)->not->toBeEmpty();
    foreach ($items as $id) {
        $test->signedInAs($as ?? $test->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/checklist/items/{$id}", ['is_completed' => true, 'notes' => 'Done'])->assertOk();
    }
}

function passQualityChecks(TestCase $test, CuttingJob $job, ?User $as = null): void
{
    $items = cutChecklistItems($job, ChecklistStage::QualityControl);
    expect($items)->not->toBeEmpty();
    foreach ($items as $id) {
        $test->signedInAs($as ?? $test->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$id}", ['result' => 'pass'])->assertOk();
    }
}

function confirmCuttingChecklist(TestCase $test, CuttingJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/cutting-jobs/{$job->id}/checklist/client-confirmation", ['channel' => 'whatsapp', 'comment' => 'Customer confirmed the cut list']);
}

function completeCutting(TestCase $test, CuttingJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/cutting-jobs/{$job->id}/complete", ['version' => cutVersion($job), 'notes' => 'All cut']);
}

function reportCuttingIssue(TestCase $test, CuttingJob $job, array $payload, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->cutter)->post("/api/v1/cutting-jobs/{$job->id}/issues", ['description' => 'Found during cutting', ...$payload], ['Accept' => 'application/json']);
}

function issueVersion(int $id): int
{
    return (int) ProductionIssue::query()->whereKey($id)->value('version');
}

function moveIssue(TestCase $test, int $id, string $to, array $extra = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/production-issues/{$id}/transitions", ['version' => issueVersion($id), 'to' => $to, ...$extra]);
}

/** Assigned, all cut, checklist done and confirmed, quality checks passed: ready to complete. */
function readyToCompleteCutting(TestCase $test): CuttingJob
{
    $job = cuttingReadyJob($test);
    assignCutter($test, $job)->assertOk();
    recordCut($test, $job)->assertOk();
    completeCuttingChecklist($test, $job);
    confirmCuttingChecklist($test, $job)->assertOk();
    passQualityChecks($test, $job);

    return $job;
}
