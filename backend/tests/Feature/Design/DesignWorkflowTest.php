<?php

declare(strict_types=1);

use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Organizations\Support\WorkflowSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => designWorld($this));

describe('job details', function () {
    it('are hidden and refused before payment approval', function () {
        $pending = createOrder($this);

        $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$pending->id}")->assertOk()
            ->assertJsonMissingPath('data.job_details')->assertJsonPath('data.abilities.manage_job_details', false);
        $this->signedInAs($this->owner)->post("/api/v1/orders/{$pending->id}/job-details", ['version' => $pending->version, 'design_instructions' => 'x'], ['Accept' => 'application/json'])
            ->assertStatus(409);
        $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$pending->id}/garment-list")->assertStatus(409);
    });

    it('are saved after approval with an attachment, and submission requires fabric and instructions', function () {
        $order = approvedOrder($this);
        $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/job-details/submit", ['version' => $order->version])
            ->assertStatus(422)->assertJsonValidationErrors(['material_id', 'design_instructions']);

        $this->signedInAs($this->owner)->post("/api/v1/orders/{$order->id}/job-details", [
            'version' => $order->version, 'material_id' => $this->fabric->id, 'design_instructions' => 'Navy body', 'attachment' => pdfFile('brief.pdf'),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.job_details.material.name', 'Wet look')
            ->assertJsonPath('data.job_details.attachment.name', 'brief.pdf');

        $this->signedInAs($this->second)->post("/api/v1/orders/{$order->id}/job-details", ['version' => $order->fresh()->version, 'custom_notes' => 'x'], ['Accept' => 'application/json'])->assertForbidden();
        $this->signedInAs($this->ownerB)->post("/api/v1/orders/{$order->id}/job-details", ['version' => $order->fresh()->version], ['Accept' => 'application/json'])->assertNotFound();
    });
});

describe('design task', function () {
    it('is created on submission, assigned to the configured designer, notified and listed for them', function () {
        Notification::fake();
        $order = approvedOrder($this);
        $task = submitJobDetails($this, $order);

        expect($task->designer_id)->toBe($this->designer->id)
            ->and($task->status)->toBe(DesignTaskStatus::Pending)
            ->and($order->fresh()->status)->toBe(OrderStatus::DesignInProgress);
        Notification::assertSentTo($this->designer, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'design.assigned');

        $this->signedInAs($this->designer)->getJson('/api/v1/design-tasks')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order.order_no', $order->order_no)->assertJsonPath('data.0.designer.id', $this->designer->id);
        $this->signedInAs($this->designer)->getJson("/api/v1/design-tasks/{$task->id}")->assertOk()
            ->assertJsonPath('data.job_details.design_instructions', 'Blue and white, club logo on the chest')
            ->assertJsonMissingPath('data.order.customer_phone');

        $other = $this->userWithRole(Roles::DESIGN_DESIGNER);
        $this->signedInAs($other)->getJson('/api/v1/design-tasks')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($other)->getJson("/api/v1/design-tasks/{$task->id}")->assertNotFound();

        // Submitting twice is impossible.
        $this->signedInAs($this->owner)->postJson("/api/v1/orders/{$order->id}/job-details/submit", ['version' => $order->fresh()->version])->assertStatus(409);
    });

    it('stays unassigned when no designer is configured, and can then be assigned', function () {
        Notification::fake();
        app(WorkflowSettings::class)->update($this->admin, [WorkflowSettings::DEFAULT_DESIGNER => null]);
        $task = submitJobDetails($this, approvedOrder($this));

        expect($task->designer_id)->toBeNull();
        Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'design.unassigned');

        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/assign", ['designer_id' => $this->second->id])->assertStatus(422);
        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/assign", ['designer_id' => $this->designer->id])->assertOk()->assertJsonPath('data.designer.id', $this->designer->id);
    });

    it('orders the dashboard by due date, putting a new urgent job in its priority position', function () {
        $later = submitJobDetails($this, approvedOrder($this, ['due_date' => '2026-12-20']));
        $sooner = submitJobDetails($this, approvedOrder($this, ['due_date' => '2026-11-30']));

        // Urgent job (due in 4 days): both approvals, then payment.
        $urgentOrder = createOrder($this, ['due_date' => '2026-11-13']);
        foreach ([[$this->second, 'second_admin'], [$this->ops, 'operation_admin']] as [$user, $slot]) {
            $this->signedInAs($user)->postJson("/api/v1/orders/{$urgentOrder->id}/urgent-decisions", ['slot' => $slot, 'decision' => 'approved'])->assertOk();
        }
        verifyPayment($this, recordPayment($this, $urgentOrder, 300000));
        $urgent = submitJobDetails($this, $urgentOrder);

        $ids = collect($this->signedInAs($this->owner)->getJson('/api/v1/design-tasks')->assertOk()->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$urgent->id, $sooner->id, $later->id]);
    });
});

describe('versions and corrections', function () {
    it('creates a new linked version on every submission and never overwrites', function () {
        $task = submitJobDetails($this, approvedOrder($this));
        submitVersion($this, $task)->assertOk()->assertJsonPath('data.status.value', 'in_review')->assertJsonPath('data.versions.0.version_no', 1);
        expect($task->order->fresh()->status)->toBe(OrderStatus::DesignReview);

        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => 'Make the logo bigger', 'channel' => 'whatsapp'])
            ->assertOk()->assertJsonPath('data.status.value', 'changes_requested')->assertJsonPath('data.correction_rounds', 1);
        expect($task->order->fresh()->status)->toBe(OrderStatus::DesignInProgress);

        $response = submitVersion($this, $task, ['notes' => 'Bigger logo', 'external_url' => 'https://drive.google.com/drive/folders/xyz'])->assertOk();
        $v1 = DesignVersion::query()->where('design_task_id', $task->id)->where('version_no', 1)->sole();
        $response->assertJsonPath('data.versions.0.version_no', 2)
            ->assertJsonPath('data.versions.0.previous_version_id', $v1->id)
            ->assertJsonPath('data.versions.0.external_url', 'https://drive.google.com/drive/folders/xyz')
            ->assertJsonPath('data.versions.0.uploaded_by', $this->designer->name)
            ->assertJsonPath('data.versions.1.notes', 'First draft')
            ->assertJsonCount(2, 'data.versions.1.files')
            ->assertJsonPath('data.corrections.0.reason', 'Make the logo bigger');

        expect(DesignCorrectionRequest::query()->sole()->getAttribute('resolved_in_version_id'))->not->toBeNull()
            ->and(fn () => $v1->forceFill(['notes' => 'changed'])->save())->toThrow(LogicException::class);
    });

    it('enforces the configurable correction limit, with an audited override', function () {
        app(WorkflowSettings::class)->update($this->admin, [WorkflowSettings::MAX_CORRECTION_ROUNDS => 3]);
        $task = submitJobDetails($this, approvedOrder($this));

        foreach (range(1, 3) as $round) {
            submitVersion($this, $task)->assertOk();
            $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => "Round {$round}", 'channel' => 'phone'])->assertOk();
        }
        submitVersion($this, $task)->assertOk();
        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => 'Round 4', 'channel' => 'phone'])
            ->assertStatus(409)->assertJsonPath('code', 'correction_limit_reached');

        $this->signedInAs($this->admin)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => 'Round 4', 'channel' => 'phone'])->assertStatus(422)->assertJsonValidationErrors('override_reason');
        $this->signedInAs($this->admin)->postJson("/api/v1/design-tasks/{$task->id}/corrections", ['reason' => 'Round 4', 'channel' => 'phone', 'override_reason' => 'Customer paid for extra round'])
            ->assertOk()->assertJsonPath('data.correction_rounds', 4)->assertJsonPath('data.corrections.3.override_reason', 'Customer paid for extra round');
    });

    it('validates design files by content and links against the allow-list without fetching them', function () {
        $task = submitJobDetails($this, approvedOrder($this));
        $fakePdf = UploadedFile::fake()->createWithContent('design.pdf', '<?php echo "x";');
        $fakeCdr = UploadedFile::fake()->createWithContent('design.cdr', 'not a corel file');

        $post = fn (array $data) => $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/versions", $data, ['Accept' => 'application/json']);
        $post(['files' => [$fakePdf]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $post(['files' => [$fakeCdr]])->assertStatus(422)->assertJsonValidationErrors('files.0');
        $post(['preview' => pdfFile()])->assertStatus(422)->assertJsonValidationErrors('preview');
        foreach (['http://drive.google.com/x', 'https://evil.example.com/x', 'https://user:pw@drive.google.com/x', 'https://drive.google.com:8443/x', 'https://169.254.169.254/latest', 'javascript:alert(1)'] as $url) {
            $post(['external_url' => $url])->assertStatus(422)->assertJsonValidationErrors('external_url');
        }
        $post([])->assertStatus(422)->assertJsonValidationErrors('files');

        Http::preventStrayRequests();
        $post(['files' => [cdrFile()], 'external_url' => 'https://docs.google.com/document/d/1/edit'])->assertOk();
    });
});

describe('approval', function () {
    it('allows only an authorised Page Owner of the page to approve', function () {
        $task = submitJobDetails($this, approvedOrder($this));
        submitVersion($this, $task)->assertOk();
        $approve = fn ($user) => $this->signedInAs($user)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp']);

        $approve($this->designer)->assertForbidden();
        $approve($this->second)->assertNotFound(); // no design access at all: invisible
        $approve($this->ownerB)->assertNotFound();
        $member = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]); // assigned, but not an owner of the page
        $approve($member)->assertForbidden()->assertJsonPath('code', 'not_page_owner');

        expect($task->fresh()->status)->toBe(DesignTaskStatus::InReview);
        $approve($this->owner)->assertOk()->assertJsonPath('data.status.value', 'approved');
        $approve($this->owner)->assertStatus(409);
    });

    it('completes the task only when the final PDF/CDR or Drive link is submitted', function () {
        Notification::fake();
        $task = submitJobDetails($this, approvedOrder($this));
        submitVersion($this, $task)->assertOk();
        $this->signedInAs($this->owner)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'messenger'])->assertOk();

        Notification::assertSentTo($this->designer, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'design.approved');
        expect($task->fresh()->status)->toBe(DesignTaskStatus::Approved)->and($task->order->fresh()->status)->toBe(OrderStatus::DesignApproved);

        $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/final", ['notes' => 'done'], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('files');
        submitVersion($this, $task)->assertStatus(409); // no more drafts after approval
        $this->signedInAs($this->owner)->post("/api/v1/design-tasks/{$task->id}/final", ['external_url' => 'https://drive.google.com/x'], ['Accept' => 'application/json'])->assertForbidden();

        $this->signedInAs($this->designer)->post("/api/v1/design-tasks/{$task->id}/final", ['external_url' => 'https://drive.google.com/file/d/final/view'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.status.value', 'completed')->assertJsonPath('data.versions.0.is_final', true);
        $done = DesignTask::query()->findOrFail($task->id);
        expect($done->completed_at)->not->toBeNull()->and($done->getAttribute('final_version_id'))->not->toBeNull();
    });

    it('serves design files only through signed URLs to people who can see the task', function () {
        $task = submitJobDetails($this, approvedOrder($this));
        $url = submitVersion($this, $task)->json('data.versions.0.files.1.url');

        $this->signedInAs($this->owner)->get($url)->assertOk()->assertHeader('Content-Disposition');
        $this->signedInAs($this->ownerB)->get($url)->assertNotFound();
        $this->signedInAs($this->owner)->get(preg_replace('/signature=[^&]+/', 'signature=bad', $url))->assertForbidden();
    });
});
