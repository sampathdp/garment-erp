<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Current value of one bulk measurement of a job: the authoritative record the Job Card reads.
 * Written only by RecordBulkMeasurements, which also appends a BulkJobMeasurementChange.
 *
 * @property string $value
 * @property int $bulk_measurement_field_id
 * @property Carbon|null $entered_at
 */
class BulkJobMeasurement extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_job_id', 'bulk_measurement_field_id'];

    protected function casts(): array
    {
        return ['value' => 'decimal:3', 'bulk_measurement_field_id' => 'integer', 'entered_at' => 'datetime'];
    }

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<BulkMeasurementField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(BulkMeasurementField::class, 'bulk_measurement_field_id');
    }

    /** @return BelongsTo<User, $this> */
    public function enterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
