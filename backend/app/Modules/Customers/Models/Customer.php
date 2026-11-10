<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer of one page (the Facebook page they contacted). Customers never log in.
 *
 * Scope: a customer is visible to users assigned to the customer's page (or holding
 * scope.all_pages). Customers without a page (legacy rows) are visible to all-page users only.
 */
class Customer extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'customer_code', 'page_id', 'branch_id', 'name', 'phone_e164', 'whatsapp_e164', 'alt_phone_e164',
        'email', 'address_line1', 'address_line2', 'city', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'page_id' => 'integer',
            'branch_id' => 'integer',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasAllPageAccess() ? $query : $query->whereIn($this->getTable().'.page_id', $user->assignedPageIds());
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->hasAllPageAccess() || $user->canAccessPage($this->page_id);
    }
}
