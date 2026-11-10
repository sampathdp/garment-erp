<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Pricing\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkMeasurement extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_assignment_id', 'size_id', 'measurement_point_id', 'measured_value', 'accepted_reason'];

    protected function casts(): array
    {
        return [
            'measured_value' => 'decimal:2',
            'within_tolerance' => 'boolean',
            'measured_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BulkAssignment, $this> */
    public function bulkAssignment(): BelongsTo
    {
        return $this->belongsTo(BulkAssignment::class);
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** @return BelongsTo<MeasurementPoint, $this> */
    public function measurementPoint(): BelongsTo
    {
        return $this->belongsTo(MeasurementPoint::class);
    }

    /** @return BelongsTo<User, $this> */
    public function measurer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'measured_by');
    }
}
