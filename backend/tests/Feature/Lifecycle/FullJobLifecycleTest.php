<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Sewing\Models\SewingJob;

/*
| End-to-end lifecycle through the public API only, as the screens drive it, for both bulk paths:
|
|   Create Order → Verify Payment → Approve Order → Add Design Instructions → Complete Design →
|   Approve Name/Number List → Generate Invoice → Assign Bulk → Complete Bulk → Approve Bulk →
|   Send Printing → Approve Sample → Complete Printing → Complete Cutting → Upload Delivery
|   Documents → Complete Job
|
| Every step asserts the order status the step must produce; the end asserts the full status
| history, the immutable invoice snapshot and the settled balance.
*/

function statusOf(int $orderId): OrderStatus
{
    return Order::query()->findOrFail($orderId)->status;
}

it('runs a job from order intake to completion', function (string $path) {
    deliveryWorld($this);

    // 1. Create the order (submitted at once): 10 jerseys, normal due date.
    $order = createOrder($this);
    expect($order->status)->toBe(OrderStatus::PendingPaymentVerification)
        ->and($order->is_urgent)->toBeFalse()
        ->and($order->advance_required_minor)->toBe(300000);

    // 2–3. Record the 30% advance (Page Owner) and verify it (Second Admin) → Approved Order.
    $advance = recordPayment($this, $order, 300000);
    expect(statusOf($order->id))->toBe(OrderStatus::PendingPaymentVerification);
    verifyPayment($this, $advance);
    expect(statusOf($order->id))->toBe(OrderStatus::Confirmed);

    // 4. Design instructions → design task for the configured designer.
    $task = submitJobDetails($this, $order);
    expect(statusOf($order->id))->toBe(OrderStatus::DesignInProgress)
        ->and($task->designer_id)->toBe($this->designer->id);

    // 5. Design: version → review → Page Owner approval → final files (task completed).
    submitVersion($this, $task)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::DesignReview);
    $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp', 'comment' => 'Customer approved'])->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::DesignApproved);
    $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/final", ['files' => [cdrFile()], 'external_url' => 'https://drive.google.com/file/d/abc123/view'], ['Accept' => 'application/json'])->assertOk();
    expect(DesignTask::query()->findOrFail($task->id)->status->value)->toBe('completed')
        ->and(BulkJob::query()->where('order_id', $order->id)->exists())->toBeFalse(); // list not confirmed yet

    // 6–7. Name/number list → confirmation locks it and issues the invoice from the price list.
    fillList($this, $order);
    confirmList($this, $order)->assertOk();
    $invoice = Invoice::query()->where('order_id', $order->id)->sole();
    $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('sort_order')->get(['description', 'quantity', 'unit_price_minor', 'line_total_minor'])->toArray();
    // 6 short-sleeve adult × 1,000.00 + 4 long-sleeve kids × 950.00 = 9,800.00
    expect($invoice->total_minor)->toBe(980000)
        ->and(collect($lines)->sum('line_total_minor'))->toBe(980000)
        ->and(Order::query()->findOrFail($order->id)->total_minor)->toBe(980000)
        ->and(Order::query()->findOrFail($order->id)->advance_required_minor)->toBe(294000);

    // The bulk job opens automatically once design, list, invoice and advance are all in place.
    $bulk = BulkJob::query()->where('order_id', $order->id)->sole();
    expect($bulk->status->value)->toBe('pending_assignment');

    // 8–10. Bulk: assign, prepare, (internal) review + approval, complete.
    if ($path === 'internal') {
        assignInternal($this, $bulk)->assertOk();
        expect(statusOf($order->id))->toBe(OrderStatus::BulkAssigned);
        enterMeasurements($this, $bulk)->assertOk();
        expect(statusOf($order->id))->toBe(OrderStatus::BulkPreparation);
        completeChecklist($this, $bulk);
        submitBulk($this, $bulk)->assertOk();
        approveBulk($this, $bulk)->assertOk();
        confirmChecklistWithClient($this, $bulk)->assertOk();
        // Internal completion needs the WeTransfer link.
        submitFinalBulk($this, $bulk, ['transfer_url' => null])->assertStatus(422);
        submitFinalBulk($this, $bulk)->assertOk();
    } else {
        assignExternal($this, $bulk)->assertOk();
        expect(statusOf($order->id))->toBe(OrderStatus::BulkAssigned);
        enterMeasurements($this, $bulk, as: $this->ops)->assertOk();
        expect(statusOf($order->id))->toBe(OrderStatus::BulkPreparation);
        completeChecklist($this, $bulk, $this->ops);
        confirmChecklistWithClient($this, $bulk)->assertOk();
        // External completion: no designer review and no WeTransfer link.
        $this->signedInAs($this->ops)->postJson("/api/v1/bulk-jobs/{$bulk->id}/external/complete", ['version' => jobVersion($bulk), 'notes' => 'Done by supplier'])->assertOk();
    }
    expect(BulkJob::query()->findOrFail($bulk->id)->status->value)->toBe('completed')
        ->and(statusOf($order->id))->toBe(OrderStatus::PrintPreparation);

    // 11–13. Printing: send, sample, Page Owner approval, physical printing, gate pass.
    $print = PrintJob::query()->where('bulk_job_id', $bulk->id)->sole();
    sendForPrinting($this, $print)->assertOk();
    uploadSample($this, $print)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::PrintSampleReview);
    approveSample($this, $print)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::Printing);
    startPrinting($this, $print)->assertOk();
    completePrinting($this, $print)->assertOk();
    recordGatePass($this, $print)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::Cutting);

    // 14. Cutting with checklist, client confirmation and quality checks.
    $cut = CuttingJob::query()->where('print_job_id', $print->id)->sole();
    assignCutter($this, $cut)->assertOk();
    recordCut($this, $cut)->assertOk();
    completeCuttingChecklist($this, $cut);
    confirmCuttingChecklist($this, $cut)->assertOk();
    passQualityChecks($this, $cut);
    expect(cutChecklistItems($cut, ChecklistStage::QualityControl))->not->toBeEmpty();
    completeCutting($this, $cut)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::Sewing);

    // Sewing (minimal step) → ready for delivery.
    $sewing = SewingJob::query()->where('cutting_job_id', $cut->id)->sole();
    startSewing($this, $sewing)->assertOk();
    completeSewing($this, $sewing)->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::ReadyForDelivery);

    // 15. Delivery: dispatch with COD = outstanding balance, deliver, upload the waybill.
    $delivery = Delivery::query()->where('order_id', $order->id)->sole();
    $outstanding = outstandingMinor($delivery);
    expect($outstanding)->toBe(980000 - 300000);
    dispatchDelivery($this, $delivery)->assertOk()->assertJsonPath('data.cod.amount.amount_minor', $outstanding);
    expect(statusOf($order->id))->toBe(OrderStatus::OutForDelivery);
    deliverDelivery($this, $delivery, ['cod_collected_minor' => $outstanding])->assertOk();
    expect(statusOf($order->id))->toBe(OrderStatus::Delivered);
    uploadDeliveryDocument($this, $delivery, 'waybill')->assertSuccessful();

    // 16. Complete: blocked until accounts verify the COD collection, then completed.
    completeDeliveredJob($this, $delivery)->assertStatus(409)->assertJsonPath('code', 'job_incomplete');
    verifyPayment($this, Payment::query()->where('order_id', $order->id)->where('method', 'cod')->sole());
    completeDeliveredJob($this, $delivery)->assertOk();

    $final = Order::query()->findOrFail($order->id);
    expect($final->status)->toBe(OrderStatus::Completed)
        ->and($final->verified_paid_minor)->toBe(980000)
        ->and($final->completed_by)->toBe($this->driver->id);

    // The issued invoice never changed during production.
    expect(Invoice::query()->findOrFail($invoice->id)->status->value)->toBe('issued')
        ->and(InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('sort_order')->get(['description', 'quantity', 'unit_price_minor', 'line_total_minor'])->toArray())->toBe($lines);

    // Every stage was passed, in order, through TransitionOrder (history + audit entry each).
    $history = OrderStatusHistory::query()->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($h) => $h->to_status->value)->all();
    expect($history)->toBe([
        'draft', 'pending_payment_verification', 'confirmed', 'design_in_progress', 'design_review', 'design_approved',
        'bulk_assigned', 'bulk_preparation', 'print_preparation', 'print_sample_review', 'printing', 'cutting', 'sewing',
        'ready_for_delivery', 'out_for_delivery', 'delivered', 'completed',
    ]);
    expect(AuditLog::query()->where('action', 'orders.status_changed')->where('subject_id', $order->id)->count())->toBe(count($history) - 1);
})->with(['internal', 'external']);
