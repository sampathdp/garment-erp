<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Models\PrintJob;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 06 fixtures on top of bulkWorld(): helpers that complete a bulk job (internal or
 * external) and drive its printing job through the API exactly as the screens do.
 */
function completedInternalBulk(TestCase $test, ?BulkJob $job = null, array $overrides = []): BulkJob
{
    $job ??= readyBulkJob($test, $overrides);
    approvedInternalBulk($test, $job);
    submitFinalBulk($test, $job)->assertOk();

    return $job;
}

function completedExternalBulk(TestCase $test, ?BulkJob $job = null): BulkJob
{
    $job ??= readyBulkJob($test);
    assignExternal($test, $job)->assertOk();
    enterMeasurements($test, $job, as: $test->ops)->assertOk();
    completeChecklist($test, $job, $test->ops);
    confirmChecklistWithClient($test, $job)->assertOk();
    $test->signedInAs($test->ops)->postJson("/api/v1/bulk-jobs/{$job->id}/external/complete", ['version' => jobVersion($job), 'notes' => 'Done by supplier'])->assertOk();

    return $job;
}

/** A printing job opened by an internal bulk completion (status ready_for_printing). */
function printReadyJob(TestCase $test, array $overrides = []): PrintJob
{
    $bulk = completedInternalBulk($test, null, $overrides);

    return PrintJob::query()->where('bulk_job_id', $bulk->id)->sole();
}

function printVersion(PrintJob $job): int
{
    return (int) PrintJob::query()->whereKey($job->id)->value('version');
}

function printJob(TestCase $test, PrintJob $job, ?User $as = null): array
{
    return $test->signedInAs($as ?? $test->ops)->getJson("/api/v1/print-jobs/{$job->id}")->assertOk()->json('data');
}

function sendForPrinting(TestCase $test, PrintJob $job, array $extra = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/print-jobs/{$job->id}/send", [
        'version' => printVersion($job), 'recipient_name' => 'Colombo Sublimation', 'notes' => 'Use the approved bulk files', ...$extra,
    ]);
}

function uploadSample(TestCase $test, PrintJob $job, array $extra = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->post("/api/v1/print-jobs/{$job->id}/samples", [
        'version' => printVersion($job), 'image' => pngFile('sample.png'), 'notes' => 'First sample print', ...$extra,
    ], ['Accept' => 'application/json']);
}

function approveSample(TestCase $test, PrintJob $job, ?User $as = null, array $extra = []): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/print-jobs/{$job->id}/samples/approve", ['version' => printVersion($job), 'comment' => 'Colours are right', ...$extra]);
}

function rejectSample(TestCase $test, PrintJob $job, ?string $reason = 'Blue is too dark', ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/print-jobs/{$job->id}/samples/reject", array_filter(['version' => printVersion($job), 'reason' => $reason], fn ($v) => $v !== null));
}

function startPrinting(TestCase $test, PrintJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/print-jobs/{$job->id}/physical-printing/start", ['version' => printVersion($job), 'notes' => 'Press 2']);
}

function completePrinting(TestCase $test, PrintJob $job, int $quantity = 10, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/print-jobs/{$job->id}/physical-printing/complete", ['version' => printVersion($job), 'printed_quantity' => $quantity, 'notes' => 'All printed']);
}

function recordGatePass(TestCase $test, PrintJob $job, array $extra = [], ?User $as = null): TestResponse
{
    $payload = array_filter([
        'gate_pass_no' => 'GP-2026-0042', 'gate_pass_date' => '2026-11-09', 'document' => pdfFile('gate-pass.pdf'), 'notes' => 'To cutting', ...$extra,
    ], fn ($v) => $v !== null);

    return $test->signedInAs($as ?? $test->ops)->post("/api/v1/print-jobs/{$job->id}/gate-passes", $payload, ['Accept' => 'application/json']);
}

/** Ready → sent → sample approved (physical printing may start). */
function approvedSampleJob(TestCase $test): PrintJob
{
    $job = printReadyJob($test);
    sendForPrinting($test, $job)->assertOk();
    uploadSample($test, $job)->assertOk();
    approveSample($test, $job)->assertOk();

    return $job;
}
