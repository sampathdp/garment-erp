<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Assignment history of a bulk job (append-only; the current row has ended_at = null).
 *
 * @property ProductionMode $mode
 * @property Carbon|null $ended_at
 */
class BulkJobAssignment extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_job_id', 'mode', 'bulk_designer_id', 'supplier_id', 'notes', 'change_reason'];

    protected function casts(): array
    {
        return ['mode' => ProductionMode::class, 'ended_at' => 'datetime', 'bulk_designer_id' => 'integer', 'supplier_id' => 'integer'];
    }

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bulkDesigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bulk_designer_id');
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
