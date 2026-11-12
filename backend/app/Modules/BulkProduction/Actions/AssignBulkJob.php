<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobAssignment;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\BulkProduction\Support\BulkChecklists;
use App\Modules\BulkProduction\Support\BulkNotifications;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Operation Admin decides how the bulk is produced:
 *
 *   INTERNAL: an internal Bulk Designer (active, holds bulk.measure, sees the job's branch/page).
 *   EXTERNAL: an external producer (active supplier); the Operation Admin records the work.
 *
 * Every assignment is a history row (type, designer/supplier, assigned by, date, notes, reason).
 * Reassignment needs a reason. External assignments are restricted: changing an external
 * assignment, or switching between internal and external, needs bulk.assignment_override.
 * The first assignment moves the order from design_approved to bulk_assigned.
 */
final class AssignBulkJob
{
    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkChecklists $checklists,
        private readonly StaffDirectory $staff,
        private readonly TransitionOrder $transition,
        private readonly BulkNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, BulkJob $job, int $expectedVersion, ProductionMode $mode, ?int $designerId, ?int $supplierId, ?string $notes, ?string $reason): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $mode, $designerId, $supplierId, $notes, $reason): BulkJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status->isClosed()) {
                throw ApiException::conflict('invalid_state', 'A completed bulk job cannot be reassigned.');
            }
            if (! in_array($order->status, [OrderStatus::DesignApproved, ...BulkWorkflow::ACTIVE_ORDER_STAGES], true)) {
                throw ApiException::conflict('order_not_in_bulk_stage', "The job is {$order->status->label()}; bulk cannot be assigned now.");
            }
            if (! $order->meetsAdvanceRequirement()) {
                throw ApiException::conflict('advance_short', 'The verified advance does not cover the required percentage of the invoice. Record the top-up first.');
            }

            $first = $job->mode === null;
            $modeChanged = ! $first && $job->mode !== $mode;
            if (! $first) {
                if ($reason === null || trim($reason) === '') {
                    throw new ApiException(422, 'validation_failed', 'Give a reason for the reassignment.', ['reason' => ['Give a reason for the reassignment.']]);
                }
                if (($job->isExternal() || $modeChanged || in_array($job->status, [BulkJobStatus::InReview, BulkJobStatus::Approved], true))
                    && ! $actor->can(Permissions::BULK_ASSIGNMENT_OVERRIDE)) {
                    throw ApiException::forbidden('external_assignment_restricted', 'This assignment change needs an authorised administrator.');
                }
            }

            [$designer, $supplier] = $this->target($mode, $designerId, $supplierId, $order);
            if (! $first && ! $modeChanged && $job->getAttribute('bulk_designer_id') === $designer?->id && $job->getAttribute('supplier_id') === $supplier?->id) {
                throw ApiException::conflict('no_change', 'The bulk job is already assigned this way.');
            }

            $before = $job->only(['mode', 'bulk_designer_id', 'supplier_id', 'status']);
            BulkJobAssignment::query()->where('bulk_job_id', $job->id)->whereNull('ended_at')->update(['ended_at' => now(), 'updated_at' => now()]);
            $assignment = new BulkJobAssignment(['bulk_job_id' => $job->id, 'mode' => $mode, 'bulk_designer_id' => $designer?->id, 'supplier_id' => $supplier?->id, 'notes' => $notes, 'change_reason' => $first ? null : $reason]);
            $assignment->forceFill(['assigned_by' => $actor->id])->save();

            $status = $first || $modeChanged ? BulkJobStatus::Assigned : $job->status;
            $job->forceFill([
                'mode' => $mode, 'bulk_designer_id' => $designer?->id, 'supplier_id' => $supplier?->id,
                'assigned_by' => $actor->id, 'assigned_at' => now(), 'assignment_notes' => $notes, 'status' => $status,
            ]);
            if ($modeChanged) {
                // A different production path: the old checklist and any review state stay as history only.
                $this->checklists->supersede($job);
                $job->forceFill(['approved_version_id' => null, 'approved_list_version_id' => null, 'approved_by' => null, 'approved_at' => null]);
            }
            $this->workflow->save($job);
            $this->checklists->ensure($job);

            if ($order->status === OrderStatus::DesignApproved) {
                $this->transition->handle($order, OrderStatus::BulkAssigned, $actor, $mode === ProductionMode::Internal ? 'Bulk assigned internally.' : 'Bulk assigned to an external producer.');
            }

            $this->audit->record($first ? 'bulk.assigned' : 'bulk.reassigned', $job, [
                'before' => $first ? null : $before,
                'after' => ['mode' => $mode->value, 'bulk_designer_id' => $designer?->id, 'supplier_id' => $supplier?->id, 'notes' => $notes, 'reason' => $first ? null : $reason],
            ], actor: $actor, branchId: $order->branch_id);

            if ($designer !== null) {
                $this->notify->assignedInternal($job, $order, $designer, $actor);
            } elseif ($supplier !== null) {
                $this->notify->assignedExternal($job, $order, $supplier->name, $actor);
            }

            return $job;
        });
    }

    /** @return array{0: User|null, 1: Supplier|null} */
    private function target(ProductionMode $mode, ?int $designerId, ?int $supplierId, Order $order): array
    {
        if ($mode === ProductionMode::Internal) {
            $designer = ($designerId === null || $designerId === 0)
                ? $this->staff->freeBulkDesignerFor($order)
                : $this->staff->eligible($designerId, Permissions::BULK_MEASURE);
            if ($designer === null || ! $designer->canAccessBranch($order->branch_id) || ! $designer->canAccessPage($order->page_id)) {
                throw new ApiException(422, 'validation_failed', 'Choose an active Bulk Designer who can work on this job.', ['bulk_designer_id' => ['Choose an active Bulk Designer who can work on this job.']]);
            }

            return [$designer, null];
        }

        $supplier = $supplierId === null ? null : Supplier::query()->whereKey($supplierId)->where('is_active', true)->first();
        if ($supplier === null) {
            throw new ApiException(422, 'validation_failed', 'Choose an active external producer.', ['supplier_id' => ['Choose an active external producer.']]);
        }

        return [null, $supplier];
    }
}
