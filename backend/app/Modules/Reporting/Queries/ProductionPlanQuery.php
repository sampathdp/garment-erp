<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Support\Time\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated production plan (WORKFLOW_REQUIREMENTS.md §3.15): the combined load of every page,
 * grouped by due date and product category, so page owners can check daily capacity before they
 * accept a new order.
 *
 *  - Counted: every order line from creation (drafts included) until sewing is done. Cancelled
 *    orders and orders at ready_for_delivery or later are left out; on-hold orders count while the
 *    status they were held in still counts.
 *  - Confirmed (red): the line's design task is completed AND its current garment list version is
 *    locked; the quantity is the locked list total. Otherwise temporary (yellow) with the estimated
 *    quantity entered at intake (order_items.quantity).
 *  - Deliberate exception to page scoping: quantities of EVERY page are summed, but only holders of
 *    production_plan.view_all_pages get page names. Everyone else sees their assigned pages by name
 *    and the rest as one anonymous "other pages" figure — no page, customer or job of another page
 *    is ever returned. Branch scope still applies.
 *  - Dates up to `to`; from `from`, or — when `from` is today or earlier — every overdue date too.
 */
final class ProductionPlanQuery
{
    public const MAX_DAYS = 120;

    public const DEFAULT_DAYS = 60;

    /** @return list<string> order statuses whose lines count as production load */
    public static function plannedStatuses(): array
    {
        $excluded = [OrderStatus::Cancelled, OrderStatus::ReadyForDelivery, OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::OnHold];
        $planned = [];
        foreach (OrderStatus::cases() as $status) {
            if (! in_array($status, $excluded, true)) {
                $planned[] = $status->value;
            }
        }

        return $planned;
    }

    /** @return array<string, mixed> */
    public function plan(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $today = BusinessDate::today()->toDateString();
        $allPages = $user->can(Permissions::PRODUCTION_PLAN_VIEW_ALL_PAGES);
        $ownPageIds = $user->assignedPageIds();
        $named = fn (int $pageId): bool => $allPages || in_array($pageId, $ownPageIds, true);

        // One row per due date × category × page, so a named page is exactly one row of its category.
        $rows = $this->rows($user, $from, $to, $today)->map(fn (\stdClass $r): array => [
            'date' => substr((string) $r->due_date, 0, 10),
            'type_id' => (int) $r->product_type_id,
            'type' => (string) $r->product_type,
            'page_id' => (int) $r->page_id,
            'temporary' => (int) $r->temporary_qty,
            'confirmed' => (int) $r->confirmed_qty,
        ]);

        $labelIds = $rows->pluck('page_id')->filter($named)->merge($ownPageIds)->unique()->values()->all();
        $pages = DB::table('pages')->whereIn('id', $labelIds === [] ? [0] : $labelIds)->get(['id', 'code', 'name'])
            ->mapWithKeys(fn (\stdClass $p): array => [(int) $p->id => ['id' => (int) $p->id, 'code' => (string) $p->code, 'name' => (string) $p->name]]);

        $days = $rows->groupBy('date')->map(fn (Collection $dayRows, string $date): array => [
            'date' => $date,
            'is_overdue' => $date < $today,
            'totals' => self::sum($dayRows),
            'categories' => $dayRows->groupBy('type_id')->map(fn (Collection $typeRows): array => [
                'product_type' => ['id' => $typeRows->first()['type_id'], 'name' => $typeRows->first()['type']],
                ...self::sum($typeRows),
                'pages' => $typeRows->filter(fn (array $r): bool => $named($r['page_id']))->map(fn (array $r): array => [
                    'page' => $pages->get($r['page_id'], ['id' => $r['page_id'], 'code' => '', 'name' => '']),
                    'is_own' => in_array($r['page_id'], $ownPageIds, true),
                    ...self::sum(collect([$r])),
                ])->values()->all(),
                'other_pages' => $allPages ? null : self::sum($typeRows->reject(fn (array $r): bool => $named($r['page_id']))),
            ])->values()->all(),
        ])->values()->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $today,
            'page_names' => $allPages ? 'all' : 'own',
            'own_pages' => collect($ownPageIds)->map(fn (int $id) => $pages->get($id))->filter()->values()->all(),
            'totals' => self::sum($rows),
            'days' => $days,
        ];
    }

    /** @return Collection<int, \stdClass> one row per due date × category × page */
    private function rows(User $user, CarbonImmutable $from, CarbonImmutable $to, string $today): Collection
    {
        $planned = self::plannedStatuses();
        $confirmed = '(design_tasks.status = ? AND garment_list_versions.status = ?)';
        $bindings = [DesignTaskStatus::Completed->value, GarmentListVersionStatus::Locked->value];

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('product_types', 'product_types.id', '=', 'order_items.product_type_id')
            ->leftJoin('design_tasks', 'design_tasks.order_item_id', '=', 'order_items.id')
            ->leftJoin('garment_lists', 'garment_lists.order_item_id', '=', 'order_items.id')
            ->leftJoin('garment_list_versions', 'garment_list_versions.id', '=', 'garment_lists.current_version_id')
            ->where(fn (Builder $q) => $q->whereIn('orders.status', $planned)
                ->orWhere(fn (Builder $h) => $h->where('orders.status', OrderStatus::OnHold->value)
                    ->where(fn (Builder $b) => $b->whereNull('orders.status_before_hold')->orWhereIn('orders.status_before_hold', $planned))))
            ->where('orders.required_delivery_date', '<=', $to->toDateString())
            ->when($from->toDateString() > $today, fn (Builder $q) => $q->where('orders.required_delivery_date', '>=', $from->toDateString()))
            ->when(! $user->hasAllBranchAccess(), fn (Builder $q) => $q->whereIn('orders.branch_id', $user->assignedBranchIds() ?: [0]))
            ->selectRaw('orders.required_delivery_date as due_date, order_items.product_type_id, product_types.name as product_type, orders.page_id')
            ->selectRaw("SUM(CASE WHEN {$confirmed} THEN garment_list_versions.total_quantity ELSE 0 END) as confirmed_qty", $bindings)
            ->selectRaw("SUM(CASE WHEN {$confirmed} THEN 0 ELSE order_items.quantity END) as temporary_qty", $bindings)
            ->groupBy('orders.required_delivery_date', 'order_items.product_type_id', 'product_types.name', 'orders.page_id')
            ->orderBy('orders.required_delivery_date')->orderBy('product_types.name')->orderBy('orders.page_id')
            ->get();
    }

    /**
     * @template TRow of array{temporary: int, confirmed: int, ...}
     *
     * @param  Collection<array-key, TRow>  $rows
     * @return array{temporary: int, confirmed: int, total: int}
     */
    private static function sum(Collection $rows): array
    {
        $temporary = (int) $rows->sum('temporary');
        $confirmed = (int) $rows->sum('confirmed');

        return ['temporary' => $temporary, 'confirmed' => $confirmed, 'total' => $temporary + $confirmed];
    }
}
