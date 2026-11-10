<?php

declare(strict_types=1);

namespace App\Modules\Design\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One correction round requested by the Page Owner on a reviewed version. `resolved_in_version_id`
 * points at the revised submission. Never edited or deleted (traceable correction history).
 */
class DesignCorrectionRequest extends Model
{
    /** @var list<string> */
    protected $fillable = ['design_task_id', 'design_review_id', 'description', 'round_no', 'requested_by', 'override_reason'];

    protected function casts(): array
    {
        return ['round_no' => 'integer'];
    }

    /** @return BelongsTo<DesignTask, $this> */
    public function designTask(): BelongsTo
    {
        return $this->belongsTo(DesignTask::class);
    }

    /** @return BelongsTo<DesignReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(DesignReview::class, 'design_review_id');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function resolvedInVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'resolved_in_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
