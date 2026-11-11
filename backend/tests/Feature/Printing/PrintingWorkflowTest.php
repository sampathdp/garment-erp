<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Printing\Actions\OpenPrintJob;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobEvent;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Models\PrintSampleDocument;
use App\Support\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    bulkWorld($this);
});

describe('printing eligibility', function () {
    it('opens exactly one printing job when an internal bulk is completed and notifies the Operation Admin', function () {
        Notification::fake();
        $bulk = completedInternalBulk($this);
        $order = Order::query()->findOrFail($bulk->order_id);

        $job = PrintJob::query()->where('bulk_job_id', $bulk->id)->sole();
        expect($job->status->value)->toBe('ready_for_printing')
            ->and($job->mode->value)->toBe('internal')
            ->and($job->garment_list_version_id)->toBe($bulk->fresh()->garment_list_version_id)
            ->and($job->bulk_version_id)->not->toBeNull()
            ->and($order->fresh()->status->value)->toBe('print_preparation');
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'printing.job_ready');
        $this->assertDatabaseHas('audit_logs', ['action' => 'printing.job_opened']);

        // Idempotent: opening again returns the same job.
        DB::transaction(function () use ($bulk, $order) {
            $again = app(OpenPrintJob::class)->handle(BulkJob::query()->findOrFail($bulk->id), Order::query()->findOrFail($order->id), $this->ops);
            expect(PrintJob::query()->where('bulk_job_id', $bulk->id)->count())->toBe(1)->and($again->id)->toBe(PrintJob::query()->value('id'));
        });
    });

    it('shows the printing information on the dashboard', function () {
        $job = printReadyJob($this);

        $row = $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        expect($row['job_no'])->toBe(Order::query()->value('order_no'))
            ->and($row['status']['value'])->toBe('ready_for_printing')
            ->and($row['material'])->toBe('Wet look')
            ->and($row['quantity'])->toBe(10)
            ->and($row['bulk_meter']['value'])->toBe('12.50')
            ->and($row['patch_meter']['value'])->toBe('3.25')
            ->and($row['mode'])->toBe('internal')
            ->and($row['bulk']['transfer_url'])->toBe(wetransferLink())
            ->and($row['bulk']['files'])->toHaveCount(1)
            ->and($row['list']['version_no'])->toBe(1)
            ->and($row['list']['total_quantity'])->toBe(10)
            ->and($row['design_preview_url'])->not->toBeNull();

        $this->signedInAs($this->ops)->get($row['design_preview_url'])->assertOk();
        $this->signedInAs($this->ops)->get($row['bulk']['files'][0]['url'])->assertOk()->assertHeader('Content-Disposition');

        $detail = printJob($this, $job);
        expect($detail['abilities']['send'])->toBeTrue()
            ->and($detail['abilities']['upload_sample'])->toBeFalse()
            ->and($detail['history'][0]['to'])->toBe('Ready for Printing');
    });

    it('opens an external printing job without a WeTransfer link', function () {
        $bulk = completedExternalBulk($this);
        $job = PrintJob::query()->where('bulk_job_id', $bulk->id)->sole();

        $data = printJob($this, $job);
        expect($data['mode'])->toBe('external')->and($data['bulk']['transfer_url'])->toBeNull()
            ->and($data['bulk']['files'])->toBe([])->and($data['bulk']['producer'])->toBe('Print House Ltd');
    });

    it('never exposes an incomplete bulk job as printing-ready', function () {
        $bulk = readyBulkJob($this);
        approvedInternalBulk($this, $bulk); // approved, final files not submitted yet

        expect(PrintJob::query()->count())->toBe(0);
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(0, 'data');

        $order = Order::query()->findOrFail($bulk->order_id);
        expect(fn () => DB::transaction(fn () => app(OpenPrintJob::class)->handle($bulk->fresh(), $order, $this->ops)))
            ->toThrow(ApiException::class, 'available only after the bulk job is completed');

        // Defence in depth: a printing row pointing at an incomplete bulk job is not listed.
        $completed = completedInternalBulk($this);
        $printJob = PrintJob::query()->where('bulk_job_id', $completed->id)->sole();
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertJsonCount(1, 'data');
        DB::table('bulk_jobs')->where('id', $completed->id)->update(['status' => 'approved']);
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertJsonCount(0, 'data');
        expect($printJob->exists)->toBeTrue();
    });

    it('lists without N+1 queries and keeps other pages hidden', function () {
        printReadyJob($this, ['due_date' => '2026-11-21']);
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertOk(); // warm the permission cache
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(1, 'data');
        $one = count(DB::getQueryLog());
        DB::disableQueryLog();

        printReadyJob($this, ['due_date' => '2026-11-22']);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(2, 'data');
        expect(count(DB::getQueryLog()))->toBe($one);
        DB::disableQueryLog();

        $this->signedInAs($this->ownerB)->getJson('/api/v1/print-jobs')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->bulkDesigner)->getJson('/api/v1/print-jobs')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/print-jobs')->assertUnauthorized();
    });
});

describe('send for printing', function () {
    it('records sender, date, recipient, notes and status and notifies the Page Owner', function () {
        Notification::fake();
        $job = printReadyJob($this);

        sendForPrinting($this, $job, ['supplier_id' => $this->supplier->id])->assertOk()
            ->assertJsonPath('data.status.value', 'sent_for_printing')
            ->assertJsonPath('data.sending.sent_by', $this->ops->name)
            ->assertJsonPath('data.sending.printing_company', 'Print House Ltd')
            ->assertJsonPath('data.sending.recipient_name', 'Colombo Sublimation')
            ->assertJsonPath('data.sending.notes', 'Use the approved bulk files');
        expect(printJob($this, $job)['sending']['sent_at'])->not->toBeNull();
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'printing.sent');
        $this->assertDatabaseHas('audit_logs', ['action' => 'printing.sent']);

        sendForPrinting($this, $job)->assertStatus(409); // already sent
    });

    it('checks permission, scope, version and the supplier', function () {
        $job = printReadyJob($this);
        sendForPrinting($this, $job, as: $this->owner)->assertForbidden();
        sendForPrinting($this, $job, as: $this->ownerB)->assertNotFound();
        sendForPrinting($this, $job, ['version' => 99])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        sendForPrinting($this, $job, ['supplier_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('supplier_id');
        sendForPrinting($this, $job, ['notes' => str_repeat('x', 1001)])->assertStatus(422);
        expect($job->fresh()->status->value)->toBe('ready_for_printing');
    });
});

describe('document restrictions', function () {
    it('lets the Operation Admin view the Job Card and the list, but refuses printing on the server', function () {
        $job = printReadyJob($this);

        $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/job-card")->assertOk()
            ->assertJsonPath('data.bulk_meter.value', '12.50')->assertJsonPath('data.printing.status.value', 'ready_for_printing');
        $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/name-list")->assertOk()->assertJsonCount(10, 'data.items');

        $data = printJob($this, $job);
        expect($data['abilities']['print_documents'])->toBeFalse()
            ->and($data['documents']['job_card']['viewable'])->toBeTrue()
            ->and($data['documents']['job_card']['printable'])->toBeFalse()
            ->and($data['documents']['name_list']['printable'])->toBeFalse()
            ->and($data['documents']['name_list']['print_locked_reason'])->toContain('after the printing sample is approved');

        foreach (['job-card', 'name-list'] as $document) {
            $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/documents/{$document}/print")
                ->assertStatus(409)->assertJsonPath('code', 'document_printing_locked');
        }

        // Still locked after sending and while the sample waits for approval or was rejected.
        sendForPrinting($this, $job)->assertOk();
        uploadSample($this, $job)->assertOk();
        $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertStatus(409);
        rejectSample($this, $job)->assertOk();
        $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/documents/name-list/print")->assertStatus(409);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'printing.document_printed']);
    });

    it('generates the printable documents only after the sample approval, escaped and audited', function () {
        $order = approvedOrder($this);
        $task = submitJobDetails($this, $order);
        $rows = jerseyRows($this);
        $rows[0]['player_name'] = '<script>alert(1)</script>';
        fillList($this, $order, $rows);
        confirmList($this, $order)->assertOk();
        completeDesign($this, $task);
        $bulk = completedInternalBulk($this, BulkJob::query()->where('order_id', $order->id)->sole());
        $job = PrintJob::query()->where('bulk_job_id', $bulk->id)->sole();
        sendForPrinting($this, $job)->assertOk();
        uploadSample($this, $job)->assertOk();
        approveSample($this, $job)->assertOk();

        expect(printJob($this, $job)['abilities']['print_documents'])->toBeTrue();
        $card = $this->signedInAs($this->ops)->get("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private');
        expect($card->getContent())->toContain($order->order_no)->toContain('Bulk Meter');

        $list = $this->signedInAs($this->ops)->get("/api/v1/print-jobs/{$job->id}/documents/name-list/print")->assertOk()->getContent();
        expect($list)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>alert(1)</script>');
        expect(DB::table('audit_logs')->where('action', 'printing.document_printed')->count())->toBe(2);
    });

    it('requires the print permission and scope', function () {
        $job = approvedSampleJob($this);
        $this->signedInAs($this->owner)->getJson("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertForbidden();
        $this->signedInAs($this->ownerB)->getJson("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertNotFound();
        $this->signedInAs($this->ownerB)->getJson("/api/v1/print-jobs/{$job->id}/job-card")->assertNotFound();
        $this->signedInAs($this->ops)->getJson("/api/v1/print-jobs/{$job->id}/documents/invoice/print")->assertNotFound();
        $this->asGuest()->getJson("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertUnauthorized();
        $this->signedInAs($this->ops)->get("/api/v1/print-jobs/{$job->id}/documents/job-card/print")->assertOk();
    });
});

describe('sample upload', function () {
    it('stores the sample image and related documents privately and notifies the Page Owner', function () {
        Notification::fake();
        $job = printReadyJob($this);
        sendForPrinting($this, $job)->assertOk();

        uploadSample($this, $job, ['documents' => [pdfFile('colour-proof.pdf')]])->assertOk()
            ->assertJsonPath('data.status.value', 'sample_pending_approval')
            ->assertJsonPath('data.samples.0.version', 1)
            ->assertJsonPath('data.samples.0.uploaded_by', $this->ops->name)
            ->assertJsonPath('data.samples.0.review_status.value', 'pending')
            ->assertJsonPath('data.samples.0.notes', 'First sample print')
            ->assertJsonCount(1, 'data.samples.0.documents');
        expect(Order::query()->findOrFail($job->order_id)->status->value)->toBe('print_sample_review');
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'printing.sample_uploaded');

        $files = PrintSampleDocument::query()->with('document')->get();
        expect($files)->toHaveCount(2);
        foreach ($files as $file) {
            expect(basename($file->document->path))->toMatch('/^[0-9a-f-]{36}$/')
                ->and($file->document->path)->not->toContain('colour-proof')->not->toContain('.png');
            Storage::disk('private')->assertExists($file->document->path);
        }

        $url = printJob($this, $job)['samples'][0]['image_url'];
        $this->signedInAs($this->owner)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->signedInAs($this->ownerB)->get($url)->assertNotFound();
        $this->signedInAs($this->owner)->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
        $this->signedInAs($this->owner)->get("/api/v1/print-jobs/{$job->id}/files/{$files[0]->document_id}")->assertForbidden(); // unsigned
    });

    it('validates the image type, size and content', function () {
        $job = printReadyJob($this);
        sendForPrinting($this, $job)->assertOk();

        uploadSample($this, $job, ['image' => null])->assertStatus(422)->assertJsonValidationErrors('image');
        uploadSample($this, $job, ['image' => UploadedFile::fake()->createWithContent('sample.png', '<?php system($_GET["c"]); ?>')])->assertStatus(422)->assertJsonValidationErrors('image');
        uploadSample($this, $job, ['image' => UploadedFile::fake()->createWithContent('sample.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        uploadSample($this, $job, ['image' => pdfFile('sample.png')])->assertStatus(422); // a PDF is not a sample image
        uploadSample($this, $job, ['image' => UploadedFile::fake()->createWithContent('sample.png', "\x89PNG\r\n\x1a\n".str_repeat('A', 64))])->assertStatus(422); // PNG magic, broken image
        uploadSample($this, $job, ['image' => UploadedFile::fake()->create('big.png', 11 * 1024, 'image/png')])->assertStatus(422)->assertJsonValidationErrors('image');
        uploadSample($this, $job, ['documents' => [UploadedFile::fake()->createWithContent('notes.html', '<html><script>x</script></html>')]])->assertStatus(422)->assertJsonValidationErrors('documents.0');
        uploadSample($this, $job, ['notes' => str_repeat('x', 2001)])->assertStatus(422);

        expect(PrintSample::query()->count())->toBe(0)->and($job->fresh()->status->value)->toBe('sent_for_printing');
        Storage::disk('private')->assertDirectoryEmpty('sample_photo');
    });

    it('checks permission, scope and state', function () {
        $job = printReadyJob($this);
        uploadSample($this, $job)->assertStatus(409); // not sent yet
        sendForPrinting($this, $job)->assertOk();
        uploadSample($this, $job, as: $this->owner)->assertForbidden();
        uploadSample($this, $job, as: $this->ownerB)->assertNotFound();
        uploadSample($this, $job, ['version' => 1])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        uploadSample($this, $job)->assertOk();
        uploadSample($this, $job)->assertStatus(409); // already waiting for approval
        expect(PrintSample::query()->count())->toBe(1);
    });
});

describe('sample review', function () {
    beforeEach(function () {
        $this->job = printReadyJob($this);
        sendForPrinting($this, $this->job)->assertOk();
        uploadSample($this, $this->job)->assertOk();
    });

    it('approves: records the reviewer and time, notifies the Operation Admin and allows physical printing', function () {
        Notification::fake();
        approveSample($this, $this->job, extra: ['channel' => 'whatsapp'])->assertOk()
            ->assertJsonPath('data.status.value', 'sample_approved')
            ->assertJsonPath('data.samples.0.review_status.value', 'approved')
            ->assertJsonPath('data.samples.0.reviewed_by', $this->owner->name)
            ->assertJsonPath('data.samples.0.is_approved', true)
            ->assertJsonPath('data.samples.0.channel', 'whatsapp')
            ->assertJsonPath('data.abilities.start_printing', false); // the owner cannot run printing
        $sample = PrintSample::query()->sole();
        expect($sample->decided_at)->not->toBeNull()->and($sample->recorded_by)->toBe($this->owner->id)
            ->and(Order::query()->findOrFail($this->job->order_id)->status->value)->toBe('printing');
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'printing.sample_approved');
        expect(printJob($this, $this->job)['abilities']['start_printing'])->toBeTrue();
        $this->assertDatabaseHas('audit_logs', ['action' => 'printing.sample_approved']);

        approveSample($this, $this->job)->assertStatus(409); // decided once
    });

    it('lets only a Page Owner of the page decide', function () {
        approveSample($this, $this->job, $this->ops)->assertForbidden();
        approveSample($this, $this->job, $this->ownerB)->assertNotFound();
        $member = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]); // page member, not owner
        approveSample($this, $this->job, $member)->assertForbidden()->assertJsonPath('code', 'not_page_owner');
        rejectSample($this, $this->job, 'Wrong', $this->ops)->assertForbidden();
        expect($this->job->fresh()->status->value)->toBe('sample_pending_approval');
    });

    it('requires a rejection reason', function () {
        rejectSample($this, $this->job, null)->assertStatus(422)->assertJsonValidationErrors('reason');
        rejectSample($this, $this->job, '  ')->assertStatus(422)->assertJsonValidationErrors('reason');
        rejectSample($this, $this->job, 'ab')->assertStatus(422);
        expect($this->job->fresh()->status->value)->toBe('sample_pending_approval')
            ->and(PrintSample::query()->sole()->decision)->toBeNull();
    });

    it('rejects with a reason, notifies the Operation Admin and accepts a corrected sample as a new version', function () {
        Notification::fake();
        rejectSample($this, $this->job, 'Blue is too dark')->assertOk()
            ->assertJsonPath('data.status.value', 'sample_rejected')
            ->assertJsonPath('data.samples.0.review_status.value', 'rejected')
            ->assertJsonPath('data.samples.0.rejection_reason', 'Blue is too dark')
            ->assertJsonPath('data.abilities.upload_sample', false);
        expect(Order::query()->findOrFail($this->job->order_id)->status->value)->toBe('print_preparation');
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'printing.sample_rejected' && str_contains($n->message, 'Blue is too dark'));

        $firstImage = PrintSample::query()->sole()->photo_document_id;
        startPrinting($this, $this->job)->assertStatus(409);

        uploadSample($this, $this->job, ['image' => pngFile('sample-v2.png'), 'notes' => 'Lighter blue'])->assertOk()
            ->assertJsonPath('data.status.value', 'sample_pending_approval')
            ->assertJsonPath('data.samples.0.version', 2)->assertJsonPath('data.samples.0.is_current', true)
            ->assertJsonPath('data.samples.0.review_status.value', 'pending')
            ->assertJsonPath('data.samples.1.version', 1)
            ->assertJsonPath('data.samples.1.review_status.value', 'rejected')
            ->assertJsonPath('data.samples.1.rejection_reason', 'Blue is too dark');
        // The first sample is never overwritten.
        $first = PrintSample::query()->where('sample_no', 1)->sole();
        expect($first->photo_document_id)->toBe($firstImage)->and($first->decision?->value)->toBe('rejected')
            ->and(PrintSample::query()->count())->toBe(2);

        approveSample($this, $this->job)->assertOk()->assertJsonPath('data.samples.0.is_approved', true)->assertJsonPath('data.samples.1.is_approved', false);
        expect(PrintJobEvent::query()->where('print_job_id', $this->job->id)->pluck('to_status')->map->value->all())->toBe([
            'ready_for_printing', 'sent_for_printing', 'sample_pending_approval', 'sample_rejected', 'sample_pending_approval', 'sample_approved',
        ]);
    });
});

describe('physical printing', function () {
    it('cannot start before the sample is approved, nor by unauthorised users', function () {
        $job = printReadyJob($this);
        startPrinting($this, $job)->assertStatus(409);
        sendForPrinting($this, $job)->assertOk();
        startPrinting($this, $job)->assertStatus(409);
        uploadSample($this, $job)->assertOk();
        startPrinting($this, $job)->assertStatus(409);
        completePrinting($this, $job)->assertStatus(409);

        approveSample($this, $job)->assertOk();
        startPrinting($this, $job, $this->owner)->assertForbidden();
        startPrinting($this, $job, $this->ownerB)->assertNotFound();
        startPrinting($this, $job, $this->bulkDesigner)->assertNotFound();
        $this->asGuest()->postJson("/api/v1/print-jobs/{$job->id}/physical-printing/start", ['version' => printVersion($job)])->assertUnauthorized();
        expect($job->fresh()->status->value)->toBe('sample_approved');

        // The approved sample is re-checked under the lock, whatever the status says.
        DB::table('print_samples')->where('print_job_id', $job->id)->update(['decision' => 'rejected']);
        startPrinting($this, $job)->assertStatus(409)->assertJsonPath('code', 'sample_not_approved');
        DB::table('print_samples')->where('print_job_id', $job->id)->update(['decision' => 'approved']);

        // Order on hold: no printing work.
        DB::table('orders')->where('id', $job->order_id)->update(['status' => 'on_hold']);
        startPrinting($this, $job)->assertStatus(409)->assertJsonPath('code', 'order_not_in_printing_stage');
    });

    it('runs physical printing and records the printed quantity', function () {
        $job = approvedSampleJob($this);
        startPrinting($this, $job)->assertOk()->assertJsonPath('data.status.value', 'physical_printing')
            ->assertJsonPath('data.status.label', 'Physical Printing In Progress')->assertJsonPath('data.printing.started_by', $this->ops->name);
        completePrinting($this, $job, 0)->assertStatus(422);
        completePrinting($this, $job, 10, $this->owner)->assertForbidden();
        completePrinting($this, $job)->assertOk()->assertJsonPath('data.status.value', 'printing_completed')
            ->assertJsonPath('data.printing.printed_quantity', 10)->assertJsonPath('data.printing.completed_by', $this->ops->name);
        expect(Order::query()->findOrFail($job->order_id)->status->value)->toBe('printing');
        $this->assertDatabaseHas('audit_logs', ['action' => 'printing.completed']);
        completePrinting($this, $job)->assertStatus(409);
        // Documents stay printable after printing.
        $this->signedInAs($this->ops)->get("/api/v1/print-jobs/{$job->id}/documents/name-list/print")->assertOk();
    });
});

describe('gate pass', function () {
    it('is recorded by the Operation Admin after physical printing', function () {
        $job = approvedSampleJob($this);
        startPrinting($this, $job)->assertOk();
        recordGatePass($this, $job)->assertStatus(409); // printing not completed yet
        completePrinting($this, $job)->assertOk();

        recordGatePass($this, $job)->assertOk()
            ->assertJsonPath('data.gate_passes.0.gate_pass_no', 'GP-2026-0042')
            ->assertJsonPath('data.gate_passes.0.gate_pass_date', '2026-11-09')
            ->assertJsonPath('data.gate_passes.0.uploaded_by', $this->ops->name)
            ->assertJsonPath('data.gate_passes.0.document.name', 'gate-pass.pdf');
        $this->assertDatabaseHas('audit_logs', ['action' => 'printing.gate_pass_recorded']);
        $url = printJob($this, $job)['gate_passes'][0]['document']['url'];
        $this->signedInAs($this->ops)->get($url)->assertOk();

        recordGatePass($this, $job)->assertStatus(409)->assertJsonPath('code', 'duplicate_gate_pass');
        recordGatePass($this, $job, ['gate_pass_no' => 'GP-2026-0043', 'document' => null])->assertOk()->assertJsonCount(2, 'data.gate_passes');
    });

    it('enforces gate pass permissions and validation', function () {
        $job = approvedSampleJob($this);
        startPrinting($this, $job)->assertOk();
        completePrinting($this, $job)->assertOk();

        recordGatePass($this, $job, as: $this->owner)->assertForbidden();
        recordGatePass($this, $job, as: $this->ownerB)->assertNotFound();
        $this->asGuest()->postJson("/api/v1/print-jobs/{$job->id}/gate-passes", ['gate_pass_no' => 'GP1', 'gate_pass_date' => '2026-11-09'])->assertUnauthorized();
        recordGatePass($this, $job, ['gate_pass_no' => ''])->assertStatus(422)->assertJsonValidationErrors('gate_pass_no');
        recordGatePass($this, $job, ['gate_pass_no' => '<b>1</b>'])->assertStatus(422)->assertJsonValidationErrors('gate_pass_no');
        recordGatePass($this, $job, ['gate_pass_date' => '2026-11-20'])->assertStatus(422)->assertJsonValidationErrors('gate_pass_date'); // future
        recordGatePass($this, $job, ['document' => UploadedFile::fake()->createWithContent('gp.exe', "MZ\x90\x00binary")])->assertStatus(422)->assertJsonValidationErrors('document');
        expect(printJob($this, $job)['gate_passes'])->toBe([]);
    });
});
