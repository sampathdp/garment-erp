<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable bulk submission (review submission or the final internal submission). Review
 * decisions are separate rows (BulkReview), so a version is never changed after it is written.
 *
 * @property int $version_no
 * @property bool $is_final
 * @property Carbon|null $submitted_at
 */
class BulkVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_job_id', 'notes'];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'is_final' => 'boolean',
            'garment_list_version_id' => 'integer',
            'previous_version_id' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bulk versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Bulk versions are immutable.'));
    }

    /** @return BelongsTo<BulkJob, $this> */
    public function bulkJob(): BelongsTo
    {
        return $this->belongsTo(BulkJob::class);
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function garmentListVersion(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return HasMany<BulkVersionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(BulkVersionFile::class);
    }

    /** @return HasMany<BulkReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(BulkReview::class);
    }
}
