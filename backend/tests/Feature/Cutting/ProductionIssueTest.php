<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Identity\Support\Roles;
use App\Modules\Notifications\WorkflowNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    cuttingWorld($this);
    $this->job = cuttingReadyJob($this);
    assignCutter($this, $this->job)->assertOk();
    recordCut($this, $this->job)->assertOk();
});

describe('issue register', function () {
    it('records every issue field and its history', function () {
        $id = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 2, 'size' => 'S', 'description' => 'Two torn front panels'])
            ->assertCreated()->json('data.id');

        $data = $this->signedInAs($this->ops)->getJson("/api/v1/production-issues/{$id}")->assertOk()->json('data');
        expect($data['job_no'])->not->toBeEmpty()
            ->and($data['category']['label'])->toBe('Damaged pieces')
            ->and($data['description'])->toBe('Two torn front panels')
            ->and($data['quantity_affected'])->toBe(2)
            ->and($data['size'])->toBe('S')
            ->and($data['reported_by'])->toBe($this->cutter->name)
            ->and($data['reported_at'])->not->toBeNull()
            ->and($data['assigned_to'])->toBeNull()
            ->and($data['resolution'])->toBeNull()
            ->and($data['status']['value'])->toBe('open')
            ->and($data['history'][0]['to'])->toBe('Open')
            ->and($data['abilities']['manage'])->toBeTrue();
        $this->assertDatabaseHas('audit_logs', ['action' => 'issues.reported']);
    });

    it('reports order-level issues through the central endpoint', function () {
        $this->signedInAs($this->ops)->post('/api/v1/production-issues', ['order_id' => $this->job->order_id, 'category' => 'material', 'description' => 'Fabric roll short'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.stage', 'cutting')->assertJsonPath('data.cutting_job_id', null);
        $this->signedInAs($this->ownerB)->post('/api/v1/production-issues', ['order_id' => $this->job->order_id, 'category' => 'material', 'description' => 'x'], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->signedInAs($this->cutter)->post('/api/v1/production-issues', ['order_id' => $this->job->order_id, 'category' => 'material', 'description' => 'Fabric'], ['Accept' => 'application/json'])
            ->assertNotFound(); // cutting staff cannot see the order itself; they report from their cutting job
        $this->signedInAs($this->ops)->postJson('/api/v1/production-issues', ['order_id' => 999999])->assertNotFound();
    });

    it('shows reporters, cutters and managers only their issues', function () {
        $cuttingIssue = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1])->assertCreated()->json('data.id');
        $orderIssue = $this->signedInAs($this->ops)->post('/api/v1/production-issues', ['order_id' => $this->job->order_id, 'category' => 'material', 'description' => 'Fabric roll short'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->signedInAs($this->ops)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($this->owner)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($this->cutter)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cuttingIssue);
        $this->signedInAs($this->cutter)->getJson("/api/v1/production-issues/{$orderIssue}")->assertNotFound();
        $this->signedInAs($this->otherCutter)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->ownerB)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($this->ownerB)->getJson("/api/v1/production-issues/{$cuttingIssue}")->assertNotFound();
        $this->signedInAs($this->ops)->getJson('/api/v1/production-issues?filter[category]=material')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($this->ops)->getJson('/api/v1/production-issues?filter[blocking]=1')->assertOk()->assertJsonCount(2, 'data');
        $this->signedInAs($this->second)->getJson('/api/v1/production-issues')->assertForbidden();
        $this->asGuest()->getJson('/api/v1/production-issues')->assertUnauthorized();
    });

    it('lets the order\'s Bulk Designer see bulk-related issues only', function () {
        $bulkIssue = reportCuttingIssue($this, $this->job, ['category' => 'bulk_error', 'description' => 'Wrong number'])->assertCreated()->json('data.id');
        $damage = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1])->assertCreated()->json('data.id');

        $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/production-issues/{$bulkIssue}")->assertOk();
        $this->signedInAs($this->bulkDesigner)->getJson("/api/v1/production-issues/{$damage}")->assertNotFound();
        $this->signedInAs($this->bulkDesigner)->getJson('/api/v1/production-issues')->assertOk()->assertJsonCount(1, 'data');
    });
});

describe('issue workflow', function () {
    it('moves through the statuses with a resolution and notifies the people involved', function () {
        Notification::fake();
        $id = reportCuttingIssue($this, $this->job, ['category' => 'incorrect_cut', 'quantity_affected' => 2])->assertCreated()->json('data.id');

        $this->signedInAs($this->ops)->patchJson("/api/v1/production-issues/{$id}", ['version' => issueVersion($id), 'assigned_to' => $this->cutter->id])
            ->assertOk()->assertJsonPath('data.assigned_to.name', $this->cutter->name);
        Notification::assertSentTo($this->cutter, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.assigned');

        moveIssue($this, $id, 'under_investigation', as: $this->cutter)->assertOk()->assertJsonPath('data.status.value', 'under_investigation');
        moveIssue($this, $id, 'correction_required', ['note' => 'Recut needed'])->assertOk();
        moveIssue($this, $id, 'resolved', as: $this->cutter)->assertUnprocessable()->assertJsonValidationErrors('resolution');
        $data = moveIssue($this, $id, 'resolved', ['resolution' => 'Two panels recut'], $this->cutter)->assertOk()->json('data');
        expect($data['resolution'])->toBe('Two panels recut')->and($data['resolved_by'])->toBe($this->cutter->name)->and($data['status']['unresolved'])->toBeFalse();
        Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'issues.status_changed');

        moveIssue($this, $id, 'closed', as: $this->cutter)->assertForbidden();
        moveIssue($this, $id, 'open', as: $this->ops)->assertUnprocessable()->assertJsonValidationErrors('note');
        moveIssue($this, $id, 'closed')->assertOk()->assertJsonPath('data.status.value', 'closed');
        moveIssue($this, $id, 'open', ['note' => 'Again'])->assertStatus(409);

        $history = $this->signedInAs($this->ops)->getJson("/api/v1/production-issues/{$id}")->json('data.history');
        // Newest first; the assignment is recorded as an Open → Open event with a note.
        expect(collect($history)->pluck('to')->all())->toBe(['Closed', 'Resolved', 'Correction Required', 'Under Investigation', 'Open', 'Open'])
            ->and($history[4]['note'])->toBe("Assigned to {$this->cutter->name}.");
        $this->assertDatabaseHas('audit_logs', ['action' => 'issues.status_changed']);
    });

    it('rejects invalid transitions, stale versions and outsiders', function () {
        $id = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1])->assertCreated()->json('data.id');

        moveIssue($this, $id, 'closed')->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        moveIssue($this, $id, 'resolved', ['resolution' => 'Fixed', 'version' => 99])->assertStatus(409)->assertJsonPath('code', 'version_conflict');
        moveIssue($this, $id, 'resolved', ['resolution' => 'Fixed'], $this->cutter)->assertForbidden(); // reporter, not assignee
        moveIssue($this, $id, 'resolved', ['resolution' => 'Fixed'], $this->ownerB)->assertNotFound();
        moveIssue($this, $id, 'bogus')->assertUnprocessable();
        $this->signedInAs($this->ops)->patchJson("/api/v1/production-issues/{$id}", ['version' => issueVersion($id), 'assigned_to' => $this->second->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
        $this->signedInAs($this->cutter)->patchJson("/api/v1/production-issues/{$id}", ['version' => issueVersion($id), 'is_blocking' => false])->assertForbidden();
    });

    it('lets issue managers mark an issue as not blocking', function () {
        $id = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1, 'is_blocking' => false])->assertCreated()->json('data.id');
        expect(ProductionIssue::query()->findOrFail($id)->is_blocking)->toBeTrue(); // reporters cannot downgrade

        $this->signedInAs($this->ops)->patchJson("/api/v1/production-issues/{$id}", ['version' => issueVersion($id), 'is_blocking' => false])->assertOk()->assertJsonPath('data.is_blocking', false);
        expect(ProductionIssue::query()->blocking()->count())->toBe(0);
    });

    it('serves the issue image only through a signed URL and the policy', function () {
        $data = reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1, 'image' => pngFile('tear.png')])->assertCreated()->json('data');

        $this->signedInAs($this->ops)->get($data['image_url'])->assertOk();
        $this->signedInAs($this->ownerB)->get($data['image_url'])->assertNotFound();
        $this->signedInAs($this->ops)->get("/api/v1/production-issues/{$data['id']}/image")->assertForbidden();
        reportCuttingIssue($this, $this->job, ['category' => 'damaged_pieces', 'quantity_affected' => 1, 'image' => pdfFile('x.pdf')])->assertUnprocessable()->assertJsonValidationErrors('image');
    });

    it('needs the issue permission on the Page Owner role to report', function () {
        $viewer = $this->userWithRole(Roles::SECOND_ADMIN, pages: [$this->pageA]);
        $this->signedInAs($viewer)->post('/api/v1/production-issues', ['order_id' => $this->job->order_id, 'category' => 'material', 'description' => 'Fabric'], ['Accept' => 'application/json'])
            ->assertForbidden();
    });
});
