<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Actions;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Support\CuttingChecklists;
use App\Modules\Cutting\Support\CuttingCompletion;
use App\Modules\Cutting\Support\CuttingNotifications;
use App\Modules\Cutting\Support\CuttingWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Actions\TransitionOrder;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Completes cutting (once; a second attempt is 409). Every rule of CuttingCompletion is
 * re-checked under the order + job locks: all sizes cut, mandatory cutting tasks done and
 * confirmed by the client, mandatory quality checks passed, no unresolved blocking issue.
 *
 * Records the completer and the responsible users (cutter, task owners, inspectors) in the
 * audit entry, moves the order cutting → sewing (the next production stage, Q21 position) and
 * notifies Operation Admins and Page Owners.
 */
final class CompleteCuttingJob
{
    public function __construct(
        private readonly CuttingWorkflow $workflow,
        private readonly CuttingCompletion $completion,
        private readonly CuttingChecklists $checklists,
        private readonly TransitionOrder $transition,
        private readonly CuttingNotifications $notify,
    ) {}

    public function handle(User $actor, CuttingJob $job, int $expectedVersion, ?string $notes): CuttingJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $notes): CuttingJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            if ($job->status === CuttingJobStatus::Completed) {
                throw ApiException::conflict('already_completed', 'This cutting job is already completed.');
            }
            if ($job->status !== CuttingJobStatus::InProgress) {
                throw ApiException::conflict('invalid_state', 'Only cutting in progress can be completed.');
            }
            $this->workflow->ensureOrderStage($order);

            $blockers = $this->completion->blockers($job);
            if ($blockers !== []) {
                throw new ApiException(409, 'cutting_incomplete', 'Cutting cannot be completed yet.', $blockers);
            }

            $job->forceFill(['completed_by' => $actor->id, 'completed_at' => now(), 'completion_notes' => $notes]);
            $this->workflow->transition($job, $order, CuttingJobStatus::Completed, $actor, 'cutting.completed', $notes, [
                'cut_quantity' => $job->cut_quantity, 'expected_quantity' => $job->planned_quantity, 'responsible' => $this->responsible($job),
            ]);
            $this->workflow->save($job);
            $this->transition->handle($order, OrderStatus::Sewing, $actor, 'Cutting completed.');
            $this->notify->completed($job, $order, $actor);

            return $job;
        });
    }

    /** @return array{cutter_id: int|null, task_users: list<int>, inspectors: list<int>} */
    private function responsible(CuttingJob $job): array
    {
        $users = fn (ChecklistStage $stage, string $column): array => ($run = $this->checklists->current($job, $stage)) === null ? []
            : ChecklistRunItem::query()->where('checklist_run_id', $run->id)->whereNotNull($column)->distinct()->pluck($column)->map(fn ($id) => (int) $id)->values()->all();

        return [
            'cutter_id' => $job->supervisor_id,
            'task_users' => array_values(array_unique([...$users(ChecklistStage::Cutting, 'completed_by'), ...$users(ChecklistStage::Cutting, 'responsible_user_id')])),
            'inspectors' => $users(ChecklistStage::QualityControl, 'completed_by'),
        ];
    }
}
