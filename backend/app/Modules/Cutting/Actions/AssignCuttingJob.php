<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobAssignment;
use App\Modules\Cutting\Support\CuttingChecklists;
use App\Modules\Cutting\Support\CuttingNotifications;
use App\Modules\Cutting\Support\CuttingWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assigns (or reassigns) the cutter of a cutting job: an active user who can record cutting
 * work (cutting.update_checklist or cutting.manage) and whose scope covers the job. Records the
 * assigned cutter, assigner, assignment date and expected completion; every assignment is a
 * history row and a reassignment needs a reason.
 *
 * The first assignment moves the job to Assigned and creates its cutting checklist and quality
 * check instances from the configured templates.
 */
final class AssignCuttingJob
{
    public function __construct(
        private readonly CuttingWorkflow $workflow,
        private readonly CuttingChecklists $checklists,
        private readonly CuttingNotifications $notify,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $actor, CuttingJob $job, int $expectedVersion, int $cutterId, Carbon $expectedCompletion, ?string $notes, ?string $reason): CuttingJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $cutterId, $expectedCompletion, $notes, $reason): CuttingJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status === CuttingJobStatus::Completed) {
                throw ApiException::conflict('invalid_state', 'This cutting job is completed.');
            }
            $this->workflow->ensureOrderStage($order);

            $cutter = User::query()->whereKey($cutterId)->where('is_active', true)->first();
            $eligible = $cutter !== null
                && ($cutter->can(Permissions::CUTTING_UPDATE_CHECKLIST) || $cutter->can(Permissions::CUTTING_MANAGE))
                && $cutter->canAccessBranch($order->branch_id) && $cutter->canAccessPage($order->page_id);
            if (! $eligible) {
                throw new ApiException(422, 'validation_failed', 'Choose an active cutter who can work on this job.', ['cutter_id' => ['Choose an active cutter who can work on this job.']]);
            }

            $reassignment = $job->supervisor_id !== null;
            if ($reassignment && $job->supervisor_id === $cutter->id && $job->expected_completion_date?->toDateString() === $expectedCompletion->toDateString()) {
                throw ApiException::conflict('already_assigned', "{$cutter->name} is already assigned with this expected completion date.");
            }
            if ($reassignment && ($reason === null || mb_strlen($reason) < 3)) {
                throw new ApiException(422, 'validation_failed', 'A reason is required to change the cutting assignment.', ['reason' => ['A reason is required to change the cutting assignment.']]);
            }

            $before = ['cutter_id' => $job->supervisor_id, 'expected_completion_date' => $job->expected_completion_date?->toDateString()];
            $job->forceFill([
                'supervisor_id' => $cutter->id, 'assigned_by' => $actor->id, 'assigned_at' => now(),
                'expected_completion_date' => $expectedCompletion->toDateString(), 'assignment_notes' => $notes,
            ]);
            CuttingJobAssignment::query()->create([
                'cutting_job_id' => $job->id, 'cutter_id' => $cutter->id, 'expected_completion_date' => $expectedCompletion->toDateString(),
                'notes' => $notes, 'reason' => $reassignment ? $reason : null, 'assigned_by' => $actor->id,
            ]);

            $details = ['cutter_id' => $cutter->id, 'expected_completion_date' => $expectedCompletion->toDateString(), 'reason' => $reason];
            if ($job->status === CuttingJobStatus::PendingAssignment) {
                $this->workflow->transition($job, $order, CuttingJobStatus::Assigned, $actor, 'cutting.assigned', $notes, $details);
            } else {
                $this->audit->record('cutting.reassigned', $job, ['before' => $before, 'after' => $details], actor: $actor, branchId: $order->branch_id);
            }
            $this->workflow->save($job);
            $this->checklists->ensureAll($job);
            $this->notify->assigned($job, $order, $cutter, $actor);

            return $job;
        });
    }
}
