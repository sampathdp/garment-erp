<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Cutting\Support\CuttingWorkflow;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Records the completed (cut) quantity per size. Expected quantities come from the locked list
 * and never change here. Rules (422 per field):
 *   - 0 ≤ cut ≤ expected for every size (no negative quantities, no over-cutting);
 *   - the total cut can never fall below the defective pieces already reported on the job
 *     (damaged, incorrect cuts, missing parts that are still unresolved).
 * Missing items = expected − cut, per size and in total.
 */
final class RecordCuttingQuantities
{
    public function __construct(private readonly CuttingWorkflow $workflow, private readonly AuditLogger $audit) {}

    /** @param  array<int, int>  $cutByRow  cutting_job_quantities.id => cut quantity */
    public function handle(User $actor, CuttingJob $job, int $expectedVersion, array $cutByRow): CuttingJob
    {
        return DB::transaction(function () use ($actor, $job, $expectedVersion, $cutByRow): CuttingJob {
            [$job, $order] = $this->workflow->lock($job, $expectedVersion);
            $this->workflow->ensureAcceptsWork($job);
            $this->workflow->ensureOrderStage($order);

            $rows = CuttingJobQuantity::query()->where('cutting_job_id', $job->id)->lockForUpdate()->get()->keyBy('id');
            $errors = [];
            foreach ($cutByRow as $rowId => $cut) {
                $row = $rows->get($rowId);
                if ($row === null) {
                    $errors["quantities.{$rowId}"] = ['This size does not belong to the cutting job.'];
                } elseif ($cut < 0) {
                    $errors["quantities.{$rowId}"] = ["The cut quantity for size {$row->size_label} cannot be negative."];
                } elseif ($cut > $row->expected_quantity) {
                    $errors["quantities.{$rowId}"] = ["Size {$row->size_label}: the cut quantity ({$cut}) cannot exceed the expected quantity ({$row->expected_quantity})."];
                }
            }
            if ($errors !== []) {
                throw new ApiException(422, 'validation_failed', 'Some quantities are invalid.', $errors);
            }

            $before = $rows->mapWithKeys(fn (CuttingJobQuantity $r) => [$r->size_label => $r->cut_quantity])->all();
            $changed = [];
            foreach ($cutByRow as $rowId => $cut) {
                $row = $rows->get($rowId);
                if ($row !== null && $row->cut_quantity !== $cut) {
                    $row->forceFill(['cut_quantity' => $cut, 'updated_by' => $actor->id])->save();
                    $changed[$row->size_label] = $cut;
                }
            }
            if ($changed === []) {
                return $job;
            }

            $total = (int) $rows->sum(fn (CuttingJobQuantity $r) => $r->cut_quantity);
            $defective = self::reportedDefects($job);
            if ($total < $defective) {
                throw new ApiException(422, 'invalid_totals', "The total cut ({$total}) cannot be lower than the defective pieces already reported ({$defective}).",
                    ['quantities' => ["The total cut ({$total}) cannot be lower than the defective pieces already reported ({$defective})."]]);
            }
            if ($total > $job->planned_quantity) {
                throw new ApiException(422, 'invalid_totals', 'The total cut cannot exceed the expected quantity.', ['quantities' => ['The total cut cannot exceed the expected quantity.']]);
            }

            $job->forceFill(['cut_quantity' => $total]);
            $this->workflow->start($job, $order, $actor);
            $this->workflow->save($job);
            $this->audit->record('cutting.quantities_recorded', $job, ['before' => ['cut' => $before], 'after' => ['cut' => $changed, 'total_cut' => $total, 'expected' => $job->planned_quantity]], actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }

    /** Unresolved defective pieces (damaged, incorrect cuts, missing parts) reported on the job. */
    public static function reportedDefects(CuttingJob $job, ?int $exceptIssueId = null): int
    {
        return (int) ProductionIssue::query()->where('cutting_job_id', $job->id)
            ->whereIn('type', array_map(fn (IssueType $t) => $t->value, array_filter(IssueType::cases(), fn (IssueType $t) => $t->isPieceDefect())))
            ->whereIn('status', IssueStatus::unresolvedValues())
            ->when($exceptIssueId !== null, fn ($q) => $q->whereKeyNot($exceptIssueId))
            ->sum('quantity_affected');
    }
}
