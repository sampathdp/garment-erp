<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A version of a name/number list. Only a `draft` version is editable; a `locked` version is
 * the confirmed snapshot (never changed again); an administrative correction opens a new draft
 * and the old locked version becomes `superseded` when the corrected list is confirmed.
 *
 * @property GarmentListVersionStatus $status
 * @property Carbon|null $locked_at
 */
class GarmentListVersion extends Model
{
    /** @var list<string> */
    protected $fillable = ['garment_list_id', 'change_reason'];

    protected function casts(): array
    {
        return [
            'status' => GarmentListVersionStatus::class,
            'version_no' => 'integer',
            'locked_at' => 'datetime',
            'total_quantity' => 'integer',
        ];
    }

    public function isEditable(): bool
    {
        return $this->status === GarmentListVersionStatus::Draft;
    }

    /** @return BelongsTo<GarmentList, $this> */
    public function garmentList(): BelongsTo
    {
        return $this->belongsTo(GarmentList::class);
    }

    /** @return HasMany<GarmentListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(GarmentListItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }
}
