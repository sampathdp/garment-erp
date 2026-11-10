<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Support;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use Illuminate\Support\Collection;

/**
 * Which measurement fields apply to a job, and how a submitted value is validated.
 * Values are decimals (meters by default) stored with 3 decimal places; a field limits the
 * number of decimals and the inclusive range [min_value, max_value]. Measurements are not money,
 * so bound comparisons use floats; the stored value is the normalised decimal string.
 */
final class BulkMeasurementRules
{
    /**
     * Active fields for the job's category, keyed by key; a category-specific field replaces a generic one.
     *
     * @return Collection<string, BulkMeasurementField>
     */
    public function fieldsFor(BulkJob $job): Collection
    {
        $productTypeId = BulkWorkflow::productTypeId($job);

        /** @var Collection<string, BulkMeasurementField> $fields */
        $fields = BulkMeasurementField::query()->applicableTo($productTypeId)->get()
            ->sortBy(fn (BulkMeasurementField $f) => $f->product_type_id === null ? 0 : 1) // specific last → wins in keyBy
            ->keyBy('key')
            ->sortBy(fn (BulkMeasurementField $f) => [$f->sort_order, $f->id]);

        return $fields;
    }

    /**
     * Labels of required fields without a value.
     *
     * @return list<string>
     */
    public function missingRequired(BulkJob $job): array
    {
        $entered = BulkJobMeasurement::query()->where('bulk_job_id', $job->id)->pluck('bulk_measurement_field_id')->map(fn ($id) => (int) $id)->all();

        return $this->fieldsFor($job)->filter(fn (BulkMeasurementField $f) => $f->is_required && ! in_array($f->id, $entered, true))
            ->map(fn (BulkMeasurementField $f) => $f->label)->values()->all();
    }

    /**
     * Normalised decimal string, or an error message.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function normalise(BulkMeasurementField $field, mixed $value): array
    {
        if (is_int($value) || is_float($value)) {
            $value = is_float($value) ? rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.') : (string) $value;
        }
        if (! is_string($value) || preg_match('/^\d{1,9}(\.\d+)?$/', trim($value)) !== 1) {
            return [null, "{$field->label} must be a positive number."];
        }
        $value = trim($value);
        $decimals = str_contains($value, '.') ? strlen(substr($value, strpos($value, '.') + 1)) : 0;
        if ($decimals > $field->decimals) {
            return [null, "{$field->label} can have at most {$field->decimals} decimal place(s)."];
        }

        $number = (float) $value;
        $min = (float) $field->min_value;
        $max = (float) $field->max_value;
        if ($number < $min || $number > $max) {
            return [null, sprintf('%s must be between %s and %s %s.', $field->label, self::display($field->min_value, $field->decimals), self::display($field->max_value, $field->decimals), $field->unit)];
        }

        return [number_format($number, 3, '.', ''), null];
    }

    public static function display(?string $value, int $decimals): ?string
    {
        return $value === null ? null : number_format((float) $value, $decimals, '.', '');
    }
}
