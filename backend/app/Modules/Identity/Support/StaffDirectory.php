<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;

/** Finds active staff who hold a permission (responsible designers, bulk designers, ...). */
final class StaffDirectory
{
    /** The user, if active and holding the permission; otherwise null. */
    public function eligible(?int $userId, string $permission): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->whereKey($userId)->where('is_active', true)->first();

        return $user !== null && $user->can($permission) ? $user : null;
    }

    /** @return Collection<int, User> */
    public function withPermission(string $permission): Collection
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()) {
            return new Collection;
        }

        return User::query()->permission($permission)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email', 'is_active']);
    }

    /**
     * Finds the least-loaded ("free") eligible Design Designer for an order.
     * Uses preferred user id as a tie-breaker when active task counts are equal.
     */
    public function freeDesignerFor(Order $order, ?int $preferredUserId = null): ?User
    {
        $candidates = $this->withPermission(Permissions::DESIGNS_UPLOAD_VERSION)
            ->filter(fn (User $u) => $u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id));

        if ($candidates->isEmpty()) {
            return null;
        }

        // Dedicated design designers take priority over super admins
        $dedicated = $candidates->filter(fn (User $u) => $u->hasRole(Roles::DESIGN_DESIGNER));
        if ($dedicated->isNotEmpty()) {
            $candidates = $dedicated;
        }

        // Active task count per designer
        $counts = DesignTask::query()
            ->whereIn('designer_id', $candidates->pluck('id'))
            ->whereNotIn('status', [DesignTaskStatus::Completed->value])
            ->selectRaw('designer_id, count(*) as active_count')
            ->groupBy('designer_id')
            ->pluck('active_count', 'designer_id');

        return $candidates->sortBy(fn (User $u) => [
            (int) ($counts->get($u->id, 0)),
            $u->id === $preferredUserId ? 0 : 1,
            $u->id,
        ])->first();
    }

    /**
     * Finds the least-loaded ("free") eligible Bulk Designer for an order.
     * Uses preferred user id as a tie-breaker when active job counts are equal.
     */
    public function freeBulkDesignerFor(Order $order, ?int $preferredUserId = null): ?User
    {
        $candidates = $this->withPermission(Permissions::BULK_MEASURE)
            ->filter(fn (User $u) => $u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id));

        if ($candidates->isEmpty()) {
            return null;
        }

        // Dedicated bulk designers take priority over super admins
        $dedicated = $candidates->filter(fn (User $u) => $u->hasAnyRole([Roles::BULK_DESIGNER, Roles::EXTERNAL_BULK_DESIGNER]));
        if ($dedicated->isNotEmpty()) {
            $candidates = $dedicated;
        }

        // Active bulk jobs per bulk designer
        $counts = BulkJob::query()
            ->whereIn('bulk_designer_id', $candidates->pluck('id'))
            ->whereNotIn('status', [BulkJobStatus::Completed->value, BulkJobStatus::Cancelled->value])
            ->selectRaw('bulk_designer_id, count(*) as active_count')
            ->groupBy('bulk_designer_id')
            ->pluck('active_count', 'bulk_designer_id');

        return $candidates->sortBy(fn (User $u) => [
            (int) ($counts->get($u->id, 0)),
            $u->id === $preferredUserId ? 0 : 1,
            $u->id,
        ])->first();
    }

    /**
     * Returns all active design designers with their active task workload and free status.
     *
     * @return list<array{id: int, name: string, active_tasks: int, is_free: bool, has_access: bool}>
     */
    public function designersWithWorkload(?Order $order = null): array
    {
        $candidates = $this->withPermission(Permissions::DESIGNS_UPLOAD_VERSION);
        if ($candidates->isEmpty()) {
            return [];
        }

        $counts = DesignTask::query()
            ->whereIn('designer_id', $candidates->pluck('id'))
            ->whereNotIn('status', [DesignTaskStatus::Completed->value])
            ->selectRaw('designer_id, count(*) as active_count')
            ->groupBy('designer_id')
            ->pluck('active_count', 'designer_id');

        $free = $order !== null ? $this->freeDesignerFor($order) : null;
        if ($free === null) {
            $dedicated = $candidates->filter(fn (User $u) => $u->hasRole(Roles::DESIGN_DESIGNER));
            $pool = $dedicated->isNotEmpty() ? $dedicated : $candidates;
            $free = $pool->sortBy(fn (User $u) => [(int) ($counts->get($u->id, 0)), $u->id])->first();
        }

        return $candidates->map(function (User $u) use ($counts, $free, $order) {
            $active = (int) ($counts->get($u->id, 0));
            $hasAccess = $order === null || ($u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id));

            return [
                'id' => $u->id,
                'name' => $u->name,
                'active_tasks' => $active,
                'is_free' => $free?->id === $u->id,
                'has_access' => $hasAccess,
            ];
        })->values()->all();
    }

    /**
     * Returns all active bulk designers with their active job workload and free status.
     *
     * @return list<array{id: int, name: string, active_jobs: int, is_free: bool, has_access: bool}>
     */
    public function bulkDesignersWithWorkload(?Order $order = null): array
    {
        $candidates = $this->withPermission(Permissions::BULK_MEASURE);
        if ($candidates->isEmpty()) {
            return [];
        }

        $counts = BulkJob::query()
            ->whereIn('bulk_designer_id', $candidates->pluck('id'))
            ->whereNotIn('status', [BulkJobStatus::Completed->value, BulkJobStatus::Cancelled->value])
            ->selectRaw('bulk_designer_id, count(*) as active_count')
            ->groupBy('bulk_designer_id')
            ->pluck('active_count', 'bulk_designer_id');

        $free = $order !== null ? $this->freeBulkDesignerFor($order) : null;
        if ($free === null) {
            $dedicated = $candidates->filter(fn (User $u) => $u->hasAnyRole([Roles::BULK_DESIGNER, Roles::EXTERNAL_BULK_DESIGNER]));
            $pool = $dedicated->isNotEmpty() ? $dedicated : $candidates;
            $free = $pool->sortBy(fn (User $u) => [(int) ($counts->get($u->id, 0)), $u->id])->first();
        }

        return $candidates->map(function (User $u) use ($counts, $free, $order) {
            $active = (int) ($counts->get($u->id, 0));
            $hasAccess = $order === null || ($u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id));

            return [
                'id' => $u->id,
                'name' => $u->name,
                'active_jobs' => $active,
                'is_free' => $free?->id === $u->id,
                'has_access' => $hasAccess,
            ];
        })->values()->all();
    }
}
