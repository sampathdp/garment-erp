<?php

declare(strict_types=1);

namespace App\Modules\Design\Models;

use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property DesignTaskStatus $status
 * @property Carbon|null $completed_at
 */
class DesignTask extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_id', 'order_item_id', 'title', 'designer_id'];

    protected function casts(): array
    {
        return [
            'status' => DesignTaskStatus::class,
            'version' => 'integer',
            'correction_rounds' => 'integer',
            'designer_id' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Scope for lists: tasks of orders in the user's branches/pages; users with only
     * designs.view_assigned see their own tasks; anyone else sees nothing.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccessibleTo(Builder $query, User $user, string $viewAll, string $viewAssigned): Builder
    {
        $query->whereIn('design_tasks.order_id', Order::query()->visibleTo($user)->select('orders.id'));
        if ($user->can($viewAll)) {
            return $query;
        }

        return $user->can($viewAssigned) ? $query->where('design_tasks.designer_id', $user->id) : $query->whereRaw('1 = 0');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function designer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designer_id');
    }

    /** @return HasMany<DesignVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DesignVersion::class);
    }

    /** @return HasMany<DesignCorrectionRequest, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(DesignCorrectionRequest::class);
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'current_version_id');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'approved_version_id');
    }

    /** @return BelongsTo<DesignVersion, $this> */
    public function finalVersion(): BelongsTo
    {
        return $this->belongsTo(DesignVersion::class, 'final_version_id');
    }
}
