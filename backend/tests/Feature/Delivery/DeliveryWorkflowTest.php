<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Payments\Models\Payment;
use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    deliveryWorld($this);
});

describe('sewing step', function () {
    it('opens one sewing job at cutting completion and releases the job for delivery when sewn', function () {
        Notification::fake();
        $sewing = sewingReadyJob($this);
        expect($sewing->status->value)->toBe('pending')->and($sewing->received_quantity)->toBe(10)
            ->and(Order::query()->findOrFail($sewing->order_id)->status->value)->toBe('sewing');

        completeSewing($this, $sewing)->assertStatus(409);
        startSewing($this, $sewing, $this->owner)->assertForbidden();
        startSewing($this, $sewing, $this->cutter)->assertNotFound();
        startSewing($this, $sewing)->assertOk()->assertJsonPath('data.status.value', 'in_progress');
        completeSewing($this, $sewing, 9)->assertUnprocessable()->assertJsonValidationErrors('sewn_quantity');
        completeSewing($this, $sewing)->assertOk()->assertJsonPath('data.status.value', 'completed');

        expect(Order::query()->findOrFail($sewing->order_id)->status->value)->toBe('ready_for_delivery')
            ->and(Delivery::query()->where('order_id', $sewing->order_id)->sole()->status->value)->toBe('ready');
        Notification::assertSentTo($this->driver, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'delivery.ready');
        $this->signedInAs($this->ownerB)->getJson("/api/v1/sewing-jobs/{$sewing->id}")->assertNotFound();
        expect(SewingJob::query()->count())->toBe(1);
    });
});

describe('delivery eligibility and dashboard', function () {
    it('lists only produced jobs to delivery staff of the branch with the delivery columns', function () {
        $delivery = deliveryReady($this);

        $row = $this->signedInAs($this->driver)->getJson('/api/v1/deliveries')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        expect($row['job_no'])->toBe(Order::query()->value('order_no'))
            ->and($row['customer']['name'])->toBe('Nimal Perera')
            ->and($row['branch'])->toBe('CMB')
            ->and($row['page']['code'])->toBe('JRSY')
            ->and($row['quantity'])->toBe(10)
            ->and($row['product']['category'])->toBe('Jersey')
            ->and($row['production_status']['value'])->toBe('ready_for_delivery')
            ->and($row['status']['value'])->toBe('ready');

        $otherBranchDriver = $this->userWithRole(Roles::DELIVERY_STAFF, branches: [Branch::factory()->create()]);
        $this->signedInAs($otherBranchDriver)->getJson('/api/v1/deliveries')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($otherBranchDriver)->getJson("/api/v1/deliveries/{$delivery->id}")->assertNotFound();
        $this->signedInAs($this->cutter)->getJson('/api/v1/deliveries')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/deliveries')->assertUnauthorized();
    });

    it('has no delivery record before production is completed', function () {
        sewingReadyJob($this);
        expect(Delivery::query()->count())->toBe(0);
        $this->signedInAs($this->driver)->getJson('/api/v1/deliveries')->assertOk()->assertJsonCount(0, 'data');
    });
});

describe('dispatch and delivery', function () {
    it('dispatches with the outstanding balance as COD and records COD collection as a pending payment', function () {
        $delivery = deliveryReady($this);
        $outstanding = outstandingMinor($delivery);
        expect($outstanding)->toBeGreaterThan(0);

        dispatchDelivery($this, $delivery, ['method' => 'pickup'])->assertUnprocessable();
        $data = dispatchDelivery($this, $delivery)->assertOk()->json('data');
        expect($data['status']['value'])->toBe('dispatched')->and($data['cod']['amount']['amount_minor'])->toBe($outstanding)
            ->and($data['production_status']['value'])->toBe('out_for_delivery');
        dispatchDelivery($this, $delivery)->assertStatus(409);

        deliverDelivery($this, $delivery, ['cod_collected_minor' => $outstanding + 1])->assertUnprocessable()->assertJsonValidationErrors('cod_collected_minor');
        $data = deliverDelivery($this, $delivery, ['cod_collected_minor' => $outstanding])->assertOk()->json('data');
        expect($data['status']['value'])->toBe('delivered')->and($data['delivery']['received_by'])->toBe('Kamal (club secretary)')
            ->and($data['production_status']['value'])->toBe('delivered');

        $payment = Payment::query()->where('order_id', $delivery->order_id)->where('method', 'cod')->sole();
        expect($payment->status->value)->toBe('pending_verification')->and($payment->getAttribute('delivery_id'))->toBe($delivery->id)
            ->and((int) $payment->getAttribute('recorded_by'))->toBe($this->driver->id);
    });

    it('records a customer pickup straight from ready', function () {
        $delivery = deliveryReady($this);
        deliverDelivery($this, $delivery)->assertStatus(409)->assertJsonPath('code', 'not_dispatched');
        deliverDelivery($this, $delivery, ['method' => 'pickup'])->assertOk()->assertJsonPath('data.method.value', 'pickup');
    });

    it('stores delivery documents privately with type, uploader and date', function () {
        $delivery = deliveryReady($this);

        $docs = uploadDeliveryDocument($this, $delivery, 'delivery_sheet')->assertOk()->json('data.documents');
        expect($docs[0]['type']['value'])->toBe('delivery_sheet')->and($docs[0]['uploaded_by'])->toBe($this->driver->name)->and($docs[0]['uploaded_at'])->not->toBeNull();
        $this->signedInAs($this->driver)->get($docs[0]['url'])->assertOk();
        $this->signedInAs($this->ownerB)->get($docs[0]['url'])->assertNotFound();

        $this->signedInAs($this->driver)->post("/api/v1/deliveries/{$delivery->id}/documents", ['type' => 'waybill', 'file' => UploadedFile::fake()->createWithContent('x.exe', 'MZ...')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->signedInAs($this->driver)->post("/api/v1/deliveries/{$delivery->id}/documents", ['type' => 'selfie', 'file' => pdfFile()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        uploadDeliveryDocument($this, $delivery, 'waybill', $this->owner)->assertForbidden();
        uploadDeliveryDocument($this, $delivery, 'waybill', $this->cutter)->assertNotFound();
    });
});

describe('final completion', function () {
    it('completes a delivered, documented and fully paid job and records who completed it', function () {
        Notification::fake();
        $delivery = deliveredAndSettled($this);

        completeDeliveredJob($this, $delivery, $this->owner)->assertForbidden();
        completeDeliveredJob($this, $delivery, $this->ownerB)->assertNotFound();
        $data = completeDeliveredJob($this, $delivery)->assertOk()->json('data');
        expect($data['production_status']['value'])->toBe('completed')
            ->and($data['completion']['completed_by'])->toBe($this->driver->name)
            ->and($data['completion']['notes'])->toBe('All good');
        $order = Order::query()->findOrFail($delivery->order_id);
        expect($order->completed_at)->not->toBeNull()->and((int) $order->getAttribute('completed_by'))->toBe($this->driver->id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'orders.completed']);
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'order.completed');

        completeDeliveredJob($this, $delivery)->assertStatus(409);
        uploadDeliveryDocument($this, $delivery)->assertStatus(409);
    });

    it('requires the document of the delivery method', function () {
        $delivery = deliveryReady($this);
        dispatchDelivery($this, $delivery)->assertOk();
        deliverDelivery($this, $delivery, ['cod_collected_minor' => outstandingMinor($delivery)])->assertOk();
        verifyPayment($this, Payment::query()->where('method', 'cod')->sole());

        uploadDeliveryDocument($this, $delivery, 'delivery_sheet')->assertOk(); // a courier needs the waybill
        completeDeliveredJob($this, $delivery)->assertStatus(409)->assertJsonPath('code', 'job_incomplete')
            ->assertJsonPath('errors.documents.0', 'Missing document: Waybill');
        $this->signedInAs($this->driver)->getJson('/api/v1/deliveries?filter[missing_documents]=1')->assertJsonCount(1, 'data');

        uploadDeliveryDocument($this, $delivery, 'waybill')->assertOk();
        $this->signedInAs($this->driver)->getJson('/api/v1/deliveries?filter[missing_documents]=1')->assertJsonCount(0, 'data');
        completeDeliveredJob($this, $delivery)->assertOk();
    });

    it('requires a verified balance of zero (COD verified by accounts)', function () {
        $delivery = deliveryReady($this);
        dispatchDelivery($this, $delivery)->assertOk();
        deliverDelivery($this, $delivery, ['cod_collected_minor' => outstandingMinor($delivery)])->assertOk();
        uploadDeliveryDocument($this, $delivery)->assertOk();

        $errors = completeDeliveredJob($this, $delivery)->assertStatus(409)->json('errors');
        expect(array_keys($errors))->toBe(['balance'])->and($errors['balance'][0])->toContain('waiting for verification');

        verifyPayment($this, Payment::query()->where('method', 'cod')->sole());
        completeDeliveredJob($this, $delivery)->assertOk();
    });

    it('is blocked by unresolved blocking production issues', function () {
        $delivery = deliveredAndSettled($this);
        $this->signedInAs($this->ops)->post('/api/v1/production-issues', ['order_id' => $delivery->order_id, 'category' => 'quality', 'description' => 'Customer reported a loose seam'], ['Accept' => 'application/json'])->assertCreated();

        completeDeliveredJob($this, $delivery)->assertStatus(409)->assertJsonStructure(['errors' => ['issues']]);
        $issue = ProductionIssue::query()->latest('id')->firstOrFail();
        moveIssue($this, $issue->id, 'resolved', ['resolution' => 'Re-stitched and returned']);
        completeDeliveredJob($this, $delivery)->assertOk();
    });

    it('cannot complete before delivery and re-checks production stages', function () {
        $delivery = deliveryReady($this);
        completeDeliveredJob($this, $delivery)->assertStatus(409); // policy: not delivered yet

        // Defence in depth: the Action re-checks production even when the records were tampered with.
        $done = deliveredAndSettled($this);
        DB::table('sewing_jobs')->where('order_id', $done->order_id)->update(['status' => 'in_progress']);
        completeDeliveredJob($this, $done)->assertStatus(409)->assertJsonPath('errors.production.0', 'Sewing is not completed.');
    });
});
