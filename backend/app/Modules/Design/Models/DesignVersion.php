<?php

declare(strict_types=1);

namespace App\Modules\Design\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One design submission. Every submission (initial, revised or final) is a NEW row with the next
 * version number and a reference to the previous version; versions are never overwritten.
 *
 * @property Carbon|null $submitted_at
 * @property Carbon|null $created_at
 */
class DesignVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['design_task_id', 'notes'];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'submitted_at' => 'datetime',
            'is_final' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Design versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Design versions are immutable.'));
    }

    /** @return BelongsTo<DesignTask, $this> */
    public function designTask(): BelongsTo
    {
        return $this->belongsTo(DesignTask::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    /** @return HasMany<DesignVersionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DesignVersionFile::class);
    }

    /** @return HasMany<DesignReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(DesignReview::class);
    }
}
