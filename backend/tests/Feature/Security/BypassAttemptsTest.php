<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
| Phase 09 security regression suite: every request here is something a signed-in user could send
| by hand (curl, a modified frontend) to get around a rule. Each one must be refused by the API.
*/

/** Path + query of an absolute signed URL returned by the API (the test client needs a relative URI). */
function relativeUrl(string $absolute): string
{
    $parts = parse_url($absolute);

    return ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
}

describe('cross-branch and cross-page isolation', function () {
    it('returns 404 for every record of a job on another page, even to holders of the same role', function () {
        deliveryWorld($this);
        $delivery = deliveredAndSettled($this);
        $orderId = $delivery->order_id;
        $payment = Payment::query()->where('order_id', $orderId)->firstOrFail();
        $task = DesignTask::query()->where('order_id', $orderId)->sole();
        $bulk = BulkJob::query()->where('order_id', $orderId)->sole();
        $print = PrintJob::query()->where('order_id', $orderId)->sole();
        $cut = CuttingJob::query()->where('order_id', $orderId)->sole();
        $sew = SewingJob::query()->where('order_id', $orderId)->sole();
        $invoice = Invoice::query()->where('order_id', $orderId)->firstOrFail();

        $reads = [
            "/api/v1/orders/{$orderId}", "/api/v1/orders/{$orderId}/payments", "/api/v1/orders/{$orderId}/invoices",
            "/api/v1/orders/{$orderId}/garment-list", "/api/v1/payments/{$payment->id}", "/api/v1/invoices/{$invoice->id}",
            "/api/v1/design-tasks/{$task->id}", "/api/v1/bulk-jobs/{$bulk->id}", "/api/v1/bulk-jobs/{$bulk->id}/job-card",
            "/api/v1/bulk-jobs/{$bulk->id}/garment-list", "/api/v1/print-jobs/{$print->id}", "/api/v1/print-jobs/{$print->id}/job-card",
            "/api/v1/print-jobs/{$print->id}/name-list", "/api/v1/cutting-jobs/{$cut->id}", "/api/v1/cutting-jobs/{$cut->id}/job-card",
            "/api/v1/sewing-jobs/{$sew->id}", "/api/v1/deliveries/{$delivery->id}",
        ];

        // Page Owner and Second Admin of page B: same roles, other page.
        foreach ([$this->ownerB, $this->secondB] as $outsider) {
            foreach ($reads as $uri) {
                $status = $this->signedInAs($outsider)->getJson($uri)->status();
                expect($status)->toBe(404, "{$uri} answered {$status} to a user of another page");
            }
        }

        // Delivery staff of another branch.
        $otherBranch = Branch::factory()->create(['organization_id' => $this->branch->organization_id, 'code' => 'KDY']);
        $otherDriver = $this->userWithRole(Roles::DELIVERY_STAFF, branches: [$otherBranch]);
        $this->signedInAs($otherDriver)->getJson("/api/v1/deliveries/{$delivery->id}")->assertNotFound();
        $this->signedInAs($otherDriver)->getJson('/api/v1/deliveries')->assertOk()->assertJsonCount(0, 'data');

        // Lists never leak the record either.
        $this->signedInAs($this->ownerB)->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->secondB)->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->ownerB)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(0, 'data');
    });

    it('refuses writes on another page’s records with 404 before any validation', function () {
        intakeWorld($this);
        $order = createOrder($this);
        $payment = recordPayment($this, $order, 300000);

        $this->signedInAs($this->secondB)->postJson("/api/v1/payments/{$payment->id}/verify", [])->assertNotFound();
        $this->signedInAs($this->secondB)->postJson("/api/v1/payments/{$payment->id}/reject", [])->assertNotFound();
        $this->signedInAs($this->ownerB)->patchJson("/api/v1/orders/{$order->id}", ['version' => $order->version, 'gross_quantity' => 1])->assertNotFound();
        $this->signedInAs($this->ownerB)->post("/api/v1/orders/{$order->id}/payments", ['amount_minor' => 1], ['Accept' => 'application/json'])->assertNotFound();
        expect(Payment::query()->where('order_id', $order->id)->count())->toBe(1);
    });

    it('never serves a private file to a user outside the job’s scope, even with a valid signed URL', function () {
        intakeWorld($this);
        Storage::fake('private');
        $order = createOrder($this);
        $payment = recordPayment($this, $order, 300000);
        $url = relativeUrl($this->signedInAs($this->owner)->getJson("/api/v1/payments/{$payment->id}")->assertOk()->json('data.evidence.url'));

        $this->signedInAs($this->owner)->get($url)->assertOk();
        $this->signedInAs($this->secondB)->get($url, ['Accept' => 'application/json'])->assertNotFound();
        $this->asGuest()->get($url, ['Accept' => 'application/json'])->assertUnauthorized();
        // Tampered or swapped signature.
        $this->signedInAs($this->owner)->get(preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $url), ['Accept' => 'application/json'])->assertForbidden();
        // Expired (5-minute TTL).
        $this->travel(6)->minutes();
        $this->signedInAs($this->owner)->get($url, ['Accept' => 'application/json'])->assertForbidden();
    });
});

describe('approving your own restricted actions', function () {
    it('never lets the payment recorder verify the payment, even with both permissions', function () {
        intakeWorld($this);
        $both = $this->userWith([Permissions::ORDERS_VIEW, Permissions::PAYMENTS_VIEW, Permissions::PAYMENTS_RECORD, Permissions::PAYMENTS_VERIFY], pages: [$this->pageA]);
        $both->givePermissionTo(Permissions::SCOPE_ALL_BRANCHES);
        $order = createOrder($this);
        $payment = recordPayment($this, $order, 300000, $both->fresh());

        $this->signedInAs($both->fresh())->postJson("/api/v1/payments/{$payment->id}/verify", ['notes' => 'mine'])
            ->assertForbidden()->assertJsonPath('code', 'separation_of_duties');
        expect($order->fresh()->status)->toBe(OrderStatus::PendingPaymentVerification);
    });

    it('never lets one person give both urgent approvals', function () {
        intakeWorld($this);
        $both = $this->userWith([Permissions::ORDERS_VIEW, Permissions::ORDERS_URGENT_APPROVE_ADMIN, Permissions::ORDERS_URGENT_APPROVE_OPERATIONS], pages: [$this->pageA]);
        $both->givePermissionTo(Permissions::SCOPE_ALL_BRANCHES);
        $order = createOrder($this, ['due_date' => '2026-11-12']); // 3 days: urgent
        expect($order->status)->toBe(OrderStatus::UrgentApprovalPending);

        $this->signedInAs($both->fresh())->postJson("/api/v1/orders/{$order->id}/urgent-decisions", ['slot' => 'second_admin', 'decision' => 'approved'])->assertOk();
        $this->signedInAs($both->fresh())->postJson("/api/v1/orders/{$order->id}/urgent-decisions", ['slot' => 'operation_admin', 'decision' => 'approved'])
            ->assertForbidden()->assertJsonPath('code', 'separation_of_duties');
        expect($order->fresh()->status)->toBe(OrderStatus::UrgentApprovalPending);
    });

    it('refuses design, bulk and sample approval from a permission holder who is not the Page Owner', function () {
        bulkWorld($this);
        // Holds every approval permission on page A, but page_user.is_owner = false.
        $approver = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]);
        $order = approvedOrder($this);
        $task = submitJobDetails($this, $order);
        submitVersion($this, $task)->assertOk();

        $this->signedInAs($approver)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp', 'comment' => 'ok'])
            ->assertForbidden()->assertJsonPath('code', 'not_page_owner');
    });
});

describe('locked and immutable data', function () {
    it('refuses every edit of a confirmed name/number list through the API', function () {
        designWorld($this);
        $order = approvedOrder($this);
        submitJobDetails($this, $order);
        fillList($this, $order);
        confirmList($this, $order)->assertOk();
        $list = garmentList($this, $order);
        $itemId = $list['items'][0]['id'];

        $this->signedInAs($this->owner)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this, 1, 0)])->assertStatus(409);
        $this->signedInAs($this->owner)->patchJson("/api/v1/garment-lists/{$list['id']}/rows/{$itemId}", ['version' => $list['version'], 'quantity' => 5])->assertStatus(409);
        $this->signedInAs($this->owner)->deleteJson("/api/v1/garment-lists/{$list['id']}/rows/{$itemId}", ['version' => $list['version']])->assertStatus(409);
        $csv = UploadedFile::fake()->createWithContent('list.csv', "player_name,number,size,quantity\nX,1,M,1\n");
        $this->signedInAs($this->owner)->post("/api/v1/garment-lists/{$list['id']}/import", ['version' => $list['version'], 'file' => $csv], ['Accept' => 'application/json'])->assertStatus(409);
    });

    it('refuses a list correction once the bulk is completed', function () {
        bulkWorld($this);
        $bulk = completedInternalBulk($this);
        $order = Order::query()->findOrFail($bulk->order_id);
        $list = garmentList($this, $order);
        $admin = $this->admin;

        $this->signedInAs($admin)->postJson("/api/v1/garment-lists/{$list['id']}/corrections", ['reason' => 'Late change of a name'])
            ->assertStatus(409)->assertJsonPath('code', 'bulk_completed');
    });
});

describe('financial information', function () {
    it('rejects server-owned money and status fields on create and update', function () {
        intakeWorld($this);
        foreach (['status' => 'confirmed', 'total_minor' => 1, 'advance_percent' => 0, 'advance_required_minor' => 0, 'verified_paid_minor' => 999999, 'is_urgent' => false] as $field => $value) {
            $this->signedInAs($this->owner)->postJson('/api/v1/orders', orderPayload($this, [$field => $value]))->assertStatus(422)->assertJsonValidationErrors($field);
        }
        $order = createOrder($this);
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['version' => $order->version, 'verified_paid_minor' => 1000000])->assertStatus(422);
        $this->signedInAs($this->owner)->patchJson("/api/v1/orders/{$order->id}", ['version' => $order->version, 'page_id' => $this->pageB->id])->assertStatus(422);
    });

    it('rejects a discount from a user without the discount permission', function () {
        intakeWorld($this);
        $sales = $this->userWith([Permissions::ORDERS_VIEW, Permissions::ORDERS_CREATE, Permissions::ORDERS_UPDATE, Permissions::ORDERS_SUBMIT, Permissions::CUSTOMERS_MANAGE], [$this->branch], [$this->pageA]);
        $this->signedInAs($sales)->postJson('/api/v1/orders', orderPayload($this, ['discount_minor' => 50000]))->assertStatus(422)->assertJsonValidationErrors('discount_minor');
    });

    it('rejects payments that are zero, negative or above the unpaid balance, and verification without permission', function () {
        intakeWorld($this);
        $order = createOrder($this);
        foreach ([0, -100, 1000001] as $amount) {
            $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/payments", [
                'amount_minor' => $amount, 'method' => 'bank_transfer', 'reference_no' => 'X1', 'payment_date' => '2026-11-09', 'evidence' => slip(),
            ], ['Accept' => 'application/json'])->assertStatus(422);
        }
        $payment = recordPayment($this, $order, 300000);
        // The Page Owner records payments but cannot verify them.
        $this->signedInAs($this->owner)->postJson("/api/v1/payments/{$payment->id}/verify", ['notes' => 'self'])->assertForbidden();
        // COD is recorded with the delivery only, never from the order screen.
        $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/payments", [
            'amount_minor' => 1000, 'method' => 'cod', 'payment_date' => '2026-11-09',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('method');
    });

    it('returns the first payment for a replayed Idempotency-Key and refuses the key for another order', function () {
        intakeWorld($this);
        $order = createOrder($this);
        $other = createOrder($this);
        $payload = ['amount_minor' => 300000, 'method' => 'bank_transfer', 'reference_no' => 'BOC-1', 'payment_date' => '2026-11-09'];
        $first = $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/payments", [...$payload, 'evidence' => slip()], ['Accept' => 'application/json', 'Idempotency-Key' => '6f1c1a0e-8c2b-4d5e-9f00-1a2b3c4d5e6f'])->assertCreated()->json('data.id');
        $again = $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/payments", [...$payload, 'evidence' => slip()], ['Accept' => 'application/json', 'Idempotency-Key' => '6f1c1a0e-8c2b-4d5e-9f00-1a2b3c4d5e6f'])->assertOk()->json('data.id');
        expect($again)->toBe($first);
        $this->signedInAs($this->owner)->post("/api/v1/orders/{$other->id}/payments", [...$payload, 'evidence' => slip()], ['Accept' => 'application/json', 'Idempotency-Key' => '6f1c1a0e-8c2b-4d5e-9f00-1a2b3c4d5e6f'])->assertStatus(409);
        expect(Payment::query()->count())->toBe(1);
    });
});

describe('skipping approval stages', function () {
    it('refuses job details before payment approval and design approval outside review', function () {
        designWorld($this);
        $order = createOrder($this);
        $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/job-details/submit", ['version' => $order->version])->assertStatus(409);

        $order = approvedOrder($this);
        $task = submitJobDetails($this, $order);
        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp'])->assertStatus(409);
        // Final files before approval.
        $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/final", ['files' => [cdrFile()]], ['Accept' => 'application/json'])->assertStatus(409);
    });

    it('requires an allow-listed https WeTransfer link for internal completion', function () {
        bulkWorld($this);
        $job = readyBulkJob($this);
        approvedInternalBulk($this, $job);
        foreach (['', 'http://wetransfer.com/downloads/x', 'https://evil.example/downloads/x', 'https://wetransfer.com.evil.example/x', 'javascript:alert(1)', 'https://user:pass@wetransfer.com/x'] as $link) {
            submitFinalBulk($this, $job, ['transfer_url' => $link])->assertStatus(422);
        }
        expect($job->fresh()->status->value)->not->toBe('completed');
        submitFinalBulk($this, $job)->assertOk();
    });

    it('refuses physical printing, document printing and gate passes before the sample is approved', function () {
        bulkWorld($this);
        $job = printReadyJob($this);
        startPrinting($this, $job)->assertStatus(409);
        $this->signedInAs($this->ops)->get("/api/v1/print-jobs/{$job->id}/documents/job-card/print", ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('code', 'document_printing_locked');
        recordGatePass($this, $job)->assertStatus(409);
        approveSample($this, $job)->assertStatus(409);

        sendForPrinting($this, $job)->assertOk();
        sendForPrinting($this, $job)->assertStatus(409);
        uploadSample($this, $job)->assertOk();
        rejectSample($this, $job, null)->assertStatus(422);
        rejectSample($this, $job, '  ')->assertStatus(422);
        startPrinting($this, $job)->assertStatus(409);
        expect(PrintJob::query()->findOrFail($job->id)->status->value)->toBe('sample_pending_approval');
    });

    it('refuses cutting completion while a mandatory quality check fails', function () {
        cuttingWorld($this);
        $job = cuttingReadyJob($this);
        assignCutter($this, $job)->assertOk();
        recordCut($this, $job)->assertOk();
        completeCuttingChecklist($this, $job);
        confirmCuttingChecklist($this, $job)->assertOk();
        $checks = cutChecklistItems($job, ChecklistStage::QualityControl);
        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$checks[0]}", ['result' => 'fail'])->assertStatus(422);
        $this->signedInAs($this->cutter)->patchJson("/api/v1/cutting-jobs/{$job->id}/quality-checks/{$checks[0]}", ['result' => 'fail', 'notes' => 'Seam misaligned'])->assertOk();

        completeCutting($this, $job)->assertStatus(409)->assertJsonPath('code', 'cutting_incomplete');
        // The cutter cannot complete the job either (cutting.complete).
        completeCutting($this, $job, $this->cutter)->assertForbidden();
    });

    it('refuses final completion without the delivery document or with an unverified balance', function () {
        deliveryWorld($this);
        $delivery = deliveryReady($this);
        dispatchDelivery($this, $delivery)->assertOk();
        dispatchDelivery($this, $delivery)->assertStatus(409);
        deliverDelivery($this, $delivery, ['cod_collected_minor' => outstandingMinor($delivery)])->assertOk();

        $response = completeDeliveredJob($this, $delivery)->assertStatus(409)->assertJsonPath('code', 'job_incomplete');
        expect(array_keys($response->json('errors')))->toContain('documents', 'balance');

        uploadDeliveryDocument($this, $delivery)->assertOk();
        completeDeliveredJob($this, $delivery)->assertStatus(409)->assertJsonPath('code', 'job_incomplete');
        // The driver who collected the cash cannot verify it.
        $cod = Payment::query()->where('order_id', $delivery->order_id)->where('method', 'cod')->sole();
        $this->signedInAs($this->driver)->postJson("/api/v1/payments/{$cod->id}/verify", ['notes' => 'mine'])->assertForbidden();
        expect(Order::query()->findOrFail($delivery->order_id)->status)->toBe(OrderStatus::Delivered);
    });
});

describe('file upload abuse', function () {
    it('rejects disguised, scriptable and oversized evidence files by content', function () {
        intakeWorld($this);
        $order = createOrder($this);
        $files = [
            UploadedFile::fake()->createWithContent('slip.png', '<?php system($_GET["c"]); ?>'),
            UploadedFile::fake()->createWithContent('slip.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('slip.html', '<html><script>alert(1)</script></html>'),
            UploadedFile::fake()->create('slip.pdf', 11 * 1024, 'application/pdf'),
        ];
        foreach ($files as $file) {
            $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/payments", [
                'amount_minor' => 1000, 'method' => 'bank_transfer', 'reference_no' => 'X', 'payment_date' => '2026-11-09', 'evidence' => $file,
            ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('evidence');
        }
        expect(Payment::query()->count())->toBe(0);
    });
});
