<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkJobMeasurementChange;
use App\Modules\BulkProduction\Support\BulkMeasurementRules;
use App\Modules\BulkProduction\Support\BulkWorkflow;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Bulk measurements (bulk meter, patch meter and the configurable fields). The values are the
 * authoritative record read by the Job Card; nothing is copied elsewhere.
 *
 *  - record(): normal entry while the job accepts work (internal: assigned designer, not while
 *    in review; external: Operation Admin). Values are validated against each field's range
 *    and decimals.
 *  - correct(): after the bulk stage was finalised (job completed), only with
 *    bulk.correct_measurements, a reason, and only until printing starts.
 *
 * Every entry and change is appended to bulk_job_measurement_changes (who, when, old → new, why).
 */
final class RecordBulkMeasurements
{
    /** Order stages in which a completed job's measurements can still be corrected. */
    private const CORRECTABLE_ORDER_STAGES = [OrderStatus::BulkPreparation, OrderStatus::PrintPreparation];

    public function __construct(
        private readonly BulkWorkflow $workflow,
        private readonly BulkMeasurementRules $rules,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  array<string, mixed>  $values  field key => value */
    public function record(User $actor, BulkJob $job, array $values): BulkJob
    {
        return $this->write($actor, $job, $values, null, false);
    }

    /** @param  array<string, mixed>  $values */
    public function correct(User $actor, BulkJob $job, array $values, string $reason): BulkJob
    {
        return $this->write($actor, $job, $values, $reason, true);
    }

    /** @param  array<string, mixed>  $values */
    private function write(User $actor, BulkJob $job, array $values, ?string $reason, bool $correction): BulkJob
    {
        return DB::transaction(function () use ($actor, $job, $values, $reason, $correction): BulkJob {
            [$job, $order] = $this->workflow->lock($job);
            if ($correction) {
                if ($job->status !== BulkJobStatus::Completed) {
                    throw ApiException::conflict('invalid_state', 'Use the normal measurement entry until the bulk is completed.');
                }
                if (! in_array($order->status, self::CORRECTABLE_ORDER_STAGES, true)) {
                    throw ApiException::conflict('production_finalized', 'Production has moved past print preparation; bulk measurements can no longer be changed.');
                }
            } else {
                if ($job->mode === null || ! $job->status->acceptsWork($job->mode)) {
                    throw ApiException::conflict('invalid_state', 'Measurements cannot be changed in the current state of the bulk job.');
                }
                $this->workflow->ensureOrderInBulkStage($order);
            }

            $fields = $this->rules->fieldsFor($job);
            $errors = [];
            $normalised = [];
            foreach ($values as $key => $value) {
                $field = $fields->get((string) $key);
                if ($field === null) {
                    $errors["values.{$key}"] = ['This measurement does not apply to the job.'];

                    continue;
                }
                [$clean, $error] = $this->rules->normalise($field, $value);
                if ($error !== null) {
                    $errors["values.{$key}"] = [$error];
                } else {
                    $normalised[$field->id] = [$field, $clean];
                }
            }
            if ($errors !== []) {
                throw new ApiException(422, 'validation_failed', 'Check the measurement values.', $errors);
            }

            $existing = BulkJobMeasurement::query()->where('bulk_job_id', $job->id)->lockForUpdate()->get()->keyBy('bulk_measurement_field_id');
            $before = [];
            $after = [];
            foreach ($normalised as $fieldId => [$field, $clean]) {
                /** @var BulkJobMeasurement|null $current */
                $current = $existing->get($fieldId);
                if ($current !== null && $current->value === $clean) {
                    continue;
                }
                $old = $current?->value;
                if ($current === null) {
                    $current = new BulkJobMeasurement(['bulk_job_id' => $job->id, 'bulk_measurement_field_id' => $fieldId]);
                    $current->forceFill(['value' => $clean, 'entered_by' => $actor->id, 'entered_at' => now()])->save();
                } else {
                    $current->forceFill(['value' => $clean, 'updated_by' => $actor->id])->save();
                }
                $change = new BulkJobMeasurementChange(['bulk_job_id' => $job->id, 'bulk_measurement_field_id' => $fieldId, 'old_value' => $old, 'new_value' => $clean, 'is_correction' => $correction, 'reason' => $reason]);
                $change->forceFill(['changed_by' => $actor->id])->save();
                $before[$field->key] = $old;
                $after[$field->key] = $clean;
            }

            if ($after === []) {
                return $job; // nothing changed
            }

            if (! $correction) {
                $this->workflow->start($job, $order, $actor);
            }
            $this->workflow->save($job);
            $this->audit->record($correction ? 'bulk.measurements_corrected' : 'bulk.measurements_recorded', $job,
                ['before' => $before, 'after' => [...$after, 'reason' => $reason]], actor: $actor, branchId: $order->branch_id);

            return $job;
        });
    }
}
