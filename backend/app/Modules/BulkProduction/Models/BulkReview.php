<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\BulkReviewDecision;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Page Owner decision on a bulk version (append-only correction history).
 *
 * @property BulkReviewDecision $decision
 * @property bool $is_system
 * @property Carbon|null $reviewed_at
 */
class BulkReview extends Model
{
    /** @var list<string> */
    protected $fillable = ['bulk_version_id', 'decision', 'reason', 'round_no'];

    protected function casts(): array
    {
        return [
            'decision' => BulkReviewDecision::class,
            'round_no' => 'integer',
            'is_system' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bulk reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('Bulk reviews are append-only.'));
    }

    /** @return BelongsTo<BulkVersion, $this> */
    public function bulkVersion(): BelongsTo
    {
        return $this->belongsTo(BulkVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
