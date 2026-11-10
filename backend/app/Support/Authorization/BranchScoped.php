<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch + page data scoping for models with a `branch_id` column.
 *
 * Apply `->visibleTo($user)` in every list query; use `isVisibleTo()` in policies.
 * Deny by default: a user with no assignments and no scope.* permission sees nothing.
 *
 * Page scoping, per model:
 *   - `protected bool $scopeByPage = true;`          model has its own `page_id` column
 *   - `protected bool $scopeByPageViaOrder = true;`  model has `order_id`; page comes from the order
 *   - `protected bool $orderIsOptional = true;`      rows without an order are visible to anyone in the branch
 */
trait BranchScoped
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $table = $this->getTable();

        if (! $user->hasAllBranchAccess()) {
            $query->whereIn("{$table}.branch_id", $user->assignedBranchIds());
        }

        if ($user->hasAllPageAccess()) {
            return $query;
        }

        $pageIds = $user->assignedPageIds();

        if ($this->scopeByPage ?? false) {
            $query->whereIn("{$table}.page_id", $pageIds);
        } elseif ($this->scopeByPageViaOrder ?? false) {
            $query->where(function (Builder $q) use ($table, $pageIds): void {
                $q->whereIn("{$table}.order_id", Order::query()->select('id')->whereIn('page_id', $pageIds));
                if ($this->orderIsOptional ?? false) {
                    $q->orWhereNull("{$table}.order_id");
                }
            });
        }

        return $query;
    }

    /**
     * List scope for task records: everything in scope for holders of $viewAll, only rows
     * assigned to the user (by $assigneeColumn) for holders of $viewAssigned, nothing otherwise.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccessibleTo(Builder $query, User $user, string $viewAll, string $viewAssigned, string $assigneeColumn): Builder
    {
        $query->visibleTo($user);

        if ($user->can($viewAll)) {
            return $query;
        }

        return $user->can($viewAssigned)
            ? $query->where($this->getTable().'.'.$assigneeColumn, $user->getKey())
            : $query->whereRaw('1 = 0');
    }

    public function isVisibleTo(User $user): bool
    {
        if (! $user->canAccessBranch($this->getAttribute('branch_id'))) {
            return false;
        }

        if ($user->hasAllPageAccess()) {
            return true;
        }

        if ($this->scopeByPage ?? false) {
            return $user->canAccessPage($this->getAttribute('page_id'));
        }

        if ($this->scopeByPageViaOrder ?? false) {
            $orderId = $this->getAttribute('order_id');
            if ($orderId === null) {
                return (bool) ($this->orderIsOptional ?? false);
            }
            $pageId = Order::query()->whereKey($orderId)->value('page_id');

            return $user->canAccessPage($pageId === null ? null : (int) $pageId);
        }

        return true;
    }
}
