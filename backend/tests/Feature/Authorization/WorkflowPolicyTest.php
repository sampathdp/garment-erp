<?php

declare(strict_types=1);

use App\Modules\BulkProduction\Enums\WorkStatus;
use App\Modules\BulkProduction\Models\BulkAssignment;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\Roles;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use Database\Seeders\PermissionSeeder;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Central policies for the workflow modules (endpoints arrive in later phases; the policies
 * are the single place every future endpoint must call). Each decision is checked for
 * scope (404), ownership (404), permission (403) and state (409).
 */
beforeEach(function () {
    $this->seed(PermissionSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->pageA = Page::factory()->create();
    $this->pageB = Page::factory()->create();
    $this->orderA = $this->makeOrder($this->branch, $this->pageA, OrderStatus::DesignReview);
    $this->orderB = $this->makeOrder($this->branch, $this->pageB, OrderStatus::DesignReview);
    $this->ownerA = $this->userWithRole(Roles::PAGE_OWNER, pages: [$this->pageA]);
});

function decision(User $user, string $ability, mixed $subject): int
{
    $response = Gate::forUser($user)->inspect($ability, $subject);

    return $response->allowed() ? 200 : ($response->status() ?? 403);
}

/** In-memory record with forced attributes (Actions that create these arrive in later phases). */
function record(string $class, array $attributes): mixed
{
    $model = new $class;
    $model->forceFill($attributes);
    $model->exists = true;

    return $model;
}

describe('cross-page access', function () {
    it('returns 404 for another page\'s job even when its ID is known', function () {
        expect(decision($this->ownerA, 'view', $this->orderA))->toBe(200)
            ->and(decision($this->ownerA, 'view', $this->orderB))->toBe(404)
            ->and(decision($this->ownerA, 'updateCustomerInfo', $this->orderB))->toBe(404)
            ->and(decision($this->ownerA, 'manageDesignInstructions', $this->orderB))->toBe(404);
    });

    it('scopes child records through their order', function () {
        $taskB = record(DesignTask::class, ['order_id' => $this->orderB->id, 'status' => DesignTaskStatus::InReview, 'designer_id' => null]);
        $paymentB = record(Payment::class, ['order_id' => $this->orderB->id, 'branch_id' => $this->branch->id, 'status' => PaymentStatus::PendingVerification, 'recorded_by' => $this->ownerA->id]);
        $listB = GarmentList::query()->forceCreate(['order_item_id' => $this->makeOrderItemId($this->orderB)]);

        expect(decision($this->ownerA, 'approve', $taskB))->toBe(404)
            ->and(decision($this->ownerA, 'update', $listB))->toBe(404)
            ->and(decision($this->userWithRole(Roles::SECOND_ADMIN, pages: [$this->pageA]), 'verify', $paymentB))->toBe(404);
    });

    it('lists only in-scope jobs', function () {
        expect(Order::query()->visibleTo($this->ownerA)->pluck('id')->all())->toBe([$this->orderA->id]);
    });
});

describe('page owner', function () {
    it('manages customers, design instructions and garment lists of the page, respecting state', function () {
        expect(decision($this->ownerA, 'updateCustomerInfo', $this->orderA))->toBe(200)
            ->and(decision($this->ownerA, 'manageDesignInstructions', $this->orderA))->toBe(200);

        $approved = $this->makeOrder($this->branch, $this->pageA, OrderStatus::Printing);
        $completed = $this->makeOrder($this->branch, $this->pageA, OrderStatus::Completed);
        expect(decision($this->ownerA, 'manageDesignInstructions', $approved))->toBe(409)
            ->and(decision($this->ownerA, 'updateCustomerInfo', $completed))->toBe(409);

        $list = GarmentList::query()->forceCreate(['order_item_id' => $this->makeOrderItemId($this->orderA)]);
        expect(decision($this->ownerA, 'update', $list))->toBe(200);

        $versionId = DB::table('garment_list_versions')->insertGetId([
            'garment_list_id' => $list->id, 'version_no' => 1, 'status' => 'locked', 'created_by' => $this->ownerA->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $list->forceFill(['current_version_id' => $versionId])->save();
        expect(decision($this->ownerA, 'update', $list))->toBe(409)
            ->and(decision($this->ownerA, 'unlock', $list))->toBe(403);
    });

    it('approves designs, bulk and printing samples only in the right state', function () {
        $this->ownerA->pages()->updateExistingPivot($this->pageA->id, ['is_owner' => true]); // final design approval: page owners only
        $task = record(DesignTask::class, ['order_id' => $this->orderA->id, 'status' => DesignTaskStatus::InReview, 'designer_id' => null]);
        expect(decision($this->ownerA, 'approve', $task))->toBe(200);
        $task->forceFill(['status' => DesignTaskStatus::InProgress]);
        expect(decision($this->ownerA, 'approve', $task))->toBe(409);

        $bulkOrder = $this->makeOrder($this->branch, $this->pageA, OrderStatus::BulkPreparation);
        expect(decision($this->ownerA, 'approveBulk', $bulkOrder))->toBe(200)
            ->and(decision($this->ownerA, 'approveBulk', $this->orderA))->toBe(409);

        $job = record(PrintJob::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => PrintJobStatus::SamplePendingApproval]);
        expect(decision($this->ownerA, 'reviewSample', $job))->toBe(200)
            ->and(decision($this->ownerA, 'uploadSample', $job))->toBe(403);
        $job->forceFill(['status' => PrintJobStatus::SentForPrinting]);
        expect(decision($this->ownerA, 'reviewSample', $job))->toBe(409);
    });

    it('cannot verify payments or do operations work', function () {
        $payment = record(Payment::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => PaymentStatus::PendingVerification, 'recorded_by' => null]);
        $assignable = $this->makeOrder($this->branch, $this->pageA, OrderStatus::DesignApproved);

        expect(decision($this->ownerA, 'verify', $payment))->toBe(403)
            ->and(decision($this->ownerA, 'assignBulk', $assignable))->toBe(403);
    });
});

describe('second admin', function () {
    it('verifies payments of assigned pages but never one they recorded', function () {
        $second = $this->userWithRole(Roles::SECOND_ADMIN, pages: [$this->pageA]);
        $payment = record(Payment::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => PaymentStatus::PendingVerification, 'recorded_by' => $this->ownerA->id]);

        expect(decision($second, 'verify', $payment))->toBe(200);

        $payment->forceFill(['recorded_by' => $second->id]);
        $response = Gate::forUser($second)->inspect('verify', $payment);
        expect($response->allowed())->toBeFalse()->and($response->code())->toBe('separation_of_duties');

        $payment->forceFill(['recorded_by' => $this->ownerA->id, 'status' => PaymentStatus::Verified]);
        expect(decision($second, 'verify', $payment))->toBe(409)
            ->and(decision($second, 'refund', $payment))->toBe(200);
    });
});

describe('assigned-only roles', function () {
    it('lets design designers work only on their own design tasks', function () {
        $designer = $this->userWithRole(Roles::DESIGN_DESIGNER);
        $colleague = $this->userWithRole(Roles::DESIGN_DESIGNER);
        $task = record(DesignTask::class, ['order_id' => $this->orderB->id, 'status' => DesignTaskStatus::InProgress, 'designer_id' => $designer->id]);

        expect(decision($designer, 'view', $task))->toBe(200)
            ->and(decision($designer, 'uploadVersion', $task))->toBe(200)
            ->and(decision($designer, 'approve', $task))->toBe(403)
            ->and(decision($designer, 'respondToCorrection', $task))->toBe(409)
            // Someone else's task is invisible, not merely forbidden.
            ->and(decision($colleague, 'view', $task))->toBe(404)
            ->and(decision($colleague, 'uploadVersion', $task))->toBe(404)
            // Designers have no access to the order itself.
            ->and(decision($designer, 'view', $this->orderB))->toBe(403);

        $task->forceFill(['status' => DesignTaskStatus::ChangesRequested]);
        expect(decision($designer, 'respondToCorrection', $task))->toBe(200);
        $task->forceFill(['status' => DesignTaskStatus::Approved]);
        expect(decision($designer, 'uploadVersion', $task))->toBe(409);
    });

    it('lets page owners see design tasks of their page without uploading versions', function () {
        $designer = $this->userWithRole(Roles::DESIGN_DESIGNER);
        $task = record(DesignTask::class, ['order_id' => $this->orderA->id, 'status' => DesignTaskStatus::InProgress, 'designer_id' => $designer->id]);

        expect(decision($this->ownerA, 'view', $task))->toBe(200)
            ->and(decision($this->ownerA, 'uploadVersion', $task))->toBe(403);
    });

    it('lets internal and external bulk designers work only on assigned bulk jobs', function () {
        foreach ([Roles::BULK_DESIGNER, Roles::EXTERNAL_BULK_DESIGNER] as $role) {
            $designer = $this->userWithRole($role);
            $other = $this->userWithRole($role);
            $job = record(BulkAssignment::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => WorkStatus::InProgress, 'bulk_designer_id' => $designer->id]);

            foreach (['view', 'enterMeasurements', 'uploadFiles', 'completeChecklist'] as $ability) {
                expect(decision($designer, $ability, $job))->toBe(200, "{$role} {$ability}")
                    ->and(decision($other, $ability, $job))->toBe(404, "{$role} {$ability} (not assigned)");
            }

            $job->forceFill(['status' => WorkStatus::Completed]);
            expect(decision($designer, 'enterMeasurements', $job))->toBe(409);
        }

        // Bulk designers report issues from their bulk job; the order itself stays hidden.
        $designer = $this->userWithRole(Roles::BULK_DESIGNER);
        $job = record(BulkAssignment::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => WorkStatus::InProgress, 'bulk_designer_id' => $designer->id]);
        expect(decision($designer, 'reportIssue', $job))->toBe(200)
            ->and(decision($designer, 'reportIssue', $this->orderA))->toBe(404)
            ->and(decision($this->userWithRole(Roles::EXTERNAL_BULK_DESIGNER), 'view', $this->orderA))->toBe(403);
    });

    it('lets cutting staff update checklists and report issues only on assigned cutting jobs', function () {
        $staff = $this->userWithRole(Roles::CUTTING_STAFF);
        $other = $this->userWithRole(Roles::CUTTING_STAFF);
        $job = record(CuttingJob::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => WorkStatus::Pending, 'supervisor_id' => $staff->id]);

        expect(decision($staff, 'updateChecklist', $job))->toBe(200)
            ->and(decision($staff, 'reportIssue', $job))->toBe(200)
            ->and(decision($staff, 'manage', $job))->toBe(403)
            ->and(decision($other, 'updateChecklist', $job))->toBe(404);
    });

    it('filters lists down to assigned records', function () {
        $designer = $this->userWithRole(Roles::BULK_DESIGNER);
        $mine = BulkAssignment::query()->forceCreate(bulkRow($this, $designer->id));
        BulkAssignment::query()->forceCreate(bulkRow($this, null));

        expect(BulkAssignment::query()->accessibleTo($designer, Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, 'bulk_designer_id')->pluck('id')->all())->toBe([$mine->id])
            ->and(BulkAssignment::query()->accessibleTo($this->userWithRole(Roles::OPERATION_ADMIN), Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, 'bulk_designer_id')->count())->toBe(2)
            ->and(BulkAssignment::query()->accessibleTo($this->userWithRole(Roles::CUTTING_STAFF), Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, 'bulk_designer_id')->count())->toBe(0);
    });
});

describe('operation admin', function () {
    it('assigns bulk, manages printing and uploads samples, but approves nothing', function () {
        $ops = $this->userWithRole(Roles::OPERATION_ADMIN);
        $assignable = $this->makeOrder($this->branch, $this->pageB, OrderStatus::DesignApproved);
        $job = record(PrintJob::class, ['order_id' => $this->orderA->id, 'branch_id' => $this->branch->id, 'status' => PrintJobStatus::ReadyForPrinting]);

        expect(decision($ops, 'assignBulk', $assignable))->toBe(200)
            ->and(decision($ops, 'assignBulk', $this->orderA))->toBe(409)
            ->and(decision($ops, 'send', $job))->toBe(200)
            ->and(decision($ops, 'uploadSample', $job))->toBe(409)
            ->and(decision($ops, 'reviewSample', $job))->toBe(403)
            ->and(decision($ops, 'printDocuments', $job))->toBe(409)
            ->and(decision($ops, 'approveBulk', $assignable))->toBe(403);
    });
});

describe('disabled accounts', function () {
    it('denies every ability once the user is inactive, via the session middleware', function () {
        $this->ownerA->forceFill(['is_active' => false])->save();

        $this->signedInAs($this->ownerA->fresh())->getJson("/api/v1/pages/{$this->pageA->id}")
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
    });
});

it('maps policy outcomes onto the API error envelope', function () {
    expect(Response::denyAsNotFound()->status())->toBe(404);
    $this->signedInAs($this->ownerA)->getJson("/api/v1/pages/{$this->pageB->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
});

function bulkRow($test, ?int $designerId): array
{
    $unitId = DB::table('production_units')->insertGetId(['branch_id' => $test->branch->id, 'name' => 'Unit '.uniqid(), 'department' => 'bulk', 'created_at' => now(), 'updated_at' => now()]);

    return [
        'order_id' => $test->orderA->id, 'order_item_id' => $test->makeOrderItemId($test->orderA), 'branch_id' => $test->branch->id,
        'mode' => 'internal', 'production_unit_id' => $unitId, 'quantity' => 10, 'due_date' => now()->addDays(10)->toDateString(),
        'status' => 'pending', 'assigned_by' => $test->ownerA->id, 'bulk_designer_id' => $designerId,
    ];
}
