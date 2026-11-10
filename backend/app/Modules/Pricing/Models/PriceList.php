<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Page-specific price list, effective from `valid_from` to `valid_to` (inclusive, Asia/Colombo).
 * A list used by an issued invoice can no longer change; pricing changes go into a new list
 * with a later effective date, so history is preserved.
 *
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
class PriceList extends Model
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasAllPageAccess() ? $query : $query->whereIn('price_lists.page_id', $user->assignedPageIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->canAccessPage((int) $this->page_id);
    }

    /** @var list<string> */
    protected $fillable = ['page_id', 'name', 'currency', 'valid_from', 'valid_to', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'page_id' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return HasMany<PriceListOptionPrice, $this> */
    public function optionPrices(): HasMany
    {
        return $this->hasMany(PriceListOptionPrice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
