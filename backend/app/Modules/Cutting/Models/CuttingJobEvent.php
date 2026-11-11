<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Models;

use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only status history of a cutting job.
 *
 * @property CuttingJobStatus|null $from_status
 * @property CuttingJobStatus $to_status
 */
class CuttingJobEvent extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['cutting_job_id', 'from_status', 'to_status', 'note', 'actor_id'];

    protected function casts(): array
    {
        return ['from_status' => CuttingJobStatus::class, 'to_status' => CuttingJobStatus::class];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
