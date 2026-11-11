<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only cutting assignment history (who was assigned, by whom, expected completion).
 *
 * @property Carbon $expected_completion_date
 */
class CuttingJobAssignment extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['cutting_job_id', 'cutter_id', 'expected_completion_date', 'notes', 'reason', 'assigned_by'];

    protected function casts(): array
    {
        return ['expected_completion_date' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function cutter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cutter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
