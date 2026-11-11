<?php

declare(strict_types=1);

use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 08 fixtures on top of cuttingWorld(): a completed cutting job (sewing job open), sewing,
 * the delivery record and the final completion, driven through the API like the screens.
 */
function deliveryWorld(TestCase $test): void
{
    cuttingWorld($test);
    $test->driver = $test->userWithRole(Roles::DELIVERY_STAFF, branches: [$test->branch]);
}

function sewingJobFor(CuttingJob $job): SewingJob
{
    return SewingJob::query()->where('cutting_job_id', $job->id)->sole();
}

function sewVersion(SewingJob $job): int
{
    return (int) SewingJob::query()->whereKey($job->id)->value('version');
}

/** Cutting completed → sewing job pending. */
function sewingReadyJob(TestCase $test): SewingJob
{
    $cutting = readyToCompleteCutting($test);
    completeCutting($test, $cutting)->assertOk();

    return sewingJobFor($cutting);
}

function startSewing(TestCase $test, SewingJob $job, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/sewing-jobs/{$job->id}/start", ['version' => sewVersion($job)]);
}

function completeSewing(TestCase $test, SewingJob $job, ?int $sewn = null, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->ops)->postJson("/api/v1/sewing-jobs/{$job->id}/complete", ['version' => sewVersion($job), 'sewn_quantity' => $sewn ?? 10, 'notes' => 'Stitched']);
}

/** Production finished: the delivery record exists (ready). */
function deliveryReady(TestCase $test): Delivery
{
    $sewing = sewingReadyJob($test);
    startSewing($test, $sewing)->assertOk();
    completeSewing($test, $sewing)->assertOk();

    return Delivery::query()->where('order_id', $sewing->order_id)->sole();
}

function deliveryVersion(Delivery $delivery): int
{
    return (int) Delivery::query()->whereKey($delivery->id)->value('version');
}

function dispatchDelivery(TestCase $test, Delivery $delivery, array $extra = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->driver)->postJson("/api/v1/deliveries/{$delivery->id}/dispatch", [
        'version' => deliveryVersion($delivery), 'method' => 'courier', 'courier_name' => 'Pronto', 'tracking_no' => 'PR-1001', ...$extra,
    ]);
}

function deliverDelivery(TestCase $test, Delivery $delivery, array $extra = [], ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->driver)->postJson("/api/v1/deliveries/{$delivery->id}/deliver", [
        'version' => deliveryVersion($delivery), 'received_by' => 'Kamal (club secretary)', ...$extra,
    ]);
}

function uploadDeliveryDocument(TestCase $test, Delivery $delivery, string $type = 'waybill', ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->driver)->post("/api/v1/deliveries/{$delivery->id}/documents", ['type' => $type, 'file' => pdfFile("{$type}.pdf")], ['Accept' => 'application/json']);
}

function completeDeliveredJob(TestCase $test, Delivery $delivery, ?User $as = null): TestResponse
{
    return $test->signedInAs($as ?? $test->driver)->postJson("/api/v1/deliveries/{$delivery->id}/complete", ['version' => deliveryVersion($delivery), 'notes' => 'All good']);
}

function outstandingMinor(Delivery $delivery): int
{
    $order = Order::query()->findOrFail($delivery->order_id);

    return max(0, $order->total_minor - $order->verified_paid_minor);
}

/** Courier delivery with the balance collected as COD and verified by the Second Admin, waybill uploaded. */
function deliveredAndSettled(TestCase $test): Delivery
{
    $delivery = deliveryReady($test);
    dispatchDelivery($test, $delivery)->assertOk();
    deliverDelivery($test, $delivery, ['cod_collected_minor' => outstandingMinor($delivery)])->assertOk();
    uploadDeliveryDocument($test, $delivery)->assertOk();
    verifyPayment($test, Payment::query()->where('order_id', $delivery->order_id)->where('method', 'cod')->sole());

    return $delivery;
}
