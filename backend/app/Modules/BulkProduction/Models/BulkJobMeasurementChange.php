<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of measurement entries and changes.
 *
 * @property string|null $old_value
 * @property string $new_value
 * @property bool $is_correction
 */
class BulkJobMeasurementChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['bulk_job_id', 'bulk_measurement_field_id', 'old_value', 'new_value', 'is_correction', 'reason'];

    protected function casts(): array
    {
        return ['old_value' => 'decimal:3', 'new_value' => 'decimal:3', 'is_correction' => 'boolean', 'bulk_measurement_field_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Measurement history is append-only.'));
        static::deleting(fn () => throw new LogicException('Measurement history is append-only.'));
    }

    /** @return BelongsTo<BulkMeasurementField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(BulkMeasurementField::class, 'bulk_measurement_field_id');
    }

    /** @return BelongsTo<User, $this> */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
