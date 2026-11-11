<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Reporting\Support\ReportScope;
use App\Support\Time\BusinessDate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports (Phase 08 §6). Every report is restricted to the caller's data scope
 * (ReportScope::orderIds) and the optional filters (created date range, branch, page).
 *
 *  - Aggregate reports are single GROUP BY queries on indexed columns, cached for
 *    CACHE_SECONDS per scope fingerprint + filters (ReportScope::remember).
 *  - List reports (overdue, upcoming, outstanding balance) are paginated and not cached.
 */
final class ReportQuery
{
    public const CACHE_SECONDS = 600;

    /** @var array<string, array{title: string, paginated: bool}> */
    public const REPORTS = [
        'jobs-by-status' => ['title' => 'Jobs by status', 'paginated' => false],
        'jobs-by-branch-page' => ['title' => 'Jobs by branch and page', 'paginated' => false],
        'overdue-jobs' => ['title' => 'Overdue jobs', 'paginated' => true],
        'upcoming-due' => ['title' => 'Upcoming due dates', 'paginated' => true],
        'payment-status' => ['title' => 'Payment status', 'paginated' => false],
        'advance-collection' => ['title' => 'Advance collection', 'paginated' => false],
        'outstanding-balance' => ['title' => 'Outstanding invoice balance', 'paginated' => true],
        'design-completion-time' => ['title' => 'Design completion time', 'paginated' => false],
        'bulk-completion-time' => ['title' => 'Bulk completion time', 'paginated' => false],
        'printing-completion-time' => ['title' => 'Printing completion time', 'paginated' => false],
        'cutting-completion-time' => ['title' => 'Cutting completion time', 'paginated' => false],
        'issue-summary' => ['title' => 'Production issue summary', 'paginated' => false],
    ];

    /**
     * @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters
     * @return array<string, mixed>
     */
    public function aggregate(string $report, User $user, array $filters): array
    {
        $key = "report:{$report}:".ReportScope::fingerprint($user).':'.md5(json_encode($filters, JSON_THROW_ON_ERROR));

        return ReportScope::remember($key, self::CACHE_SECONDS, fn (): array => [
            ...match ($report) {
                'jobs-by-status' => $this->jobsByStatus($user, $filters),
                'jobs-by-branch-page' => $this->jobsByBranchPage($user, $filters),
                'payment-status' => $this->paymentStatus($user, $filters),
                'advance-collection' => $this->advanceCollection($user, $filters),
                'design-completion-time' => $this->duration($user, $filters, 'design_tasks', 'design_tasks.created_at', 'design_tasks.completed_at', 'design_tasks.status', DesignTaskStatus::Completed->value),
                'bulk-completion-time' => $this->duration($user, $filters, 'bulk_jobs', 'bulk_jobs.created_at', 'bulk_jobs.completed_at', 'bulk_jobs.status', BulkJobStatus::Completed->value),
                'printing-completion-time' => $this->duration($user, $filters, 'print_jobs', 'print_jobs.created_at', 'print_jobs.printing_completed_at', null, null),
                'cutting-completion-time' => $this->duration($user, $filters, 'cutting_jobs', 'cutting_jobs.created_at', 'cutting_jobs.completed_at', 'cutting_jobs.status', CuttingJobStatus::Completed->value),
                'issue-summary' => $this->issueSummary($user, $filters),
                default => throw new \InvalidArgumentException("Unknown aggregate report {$report}"),
            },
            'generated_at' => now()->toIso8601ZuluString(),
        ]);
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    public function paginate(string $report, User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        $today = BusinessDate::today()->toDateString();
        $query = $this->orders($user, $filters, dated: false)
            ->with(['customer:id,name', 'branch:id,code', 'page:id,code'])
            ->select(['orders.id', 'orders.order_no', 'orders.customer_id', 'orders.branch_id', 'orders.page_id', 'orders.status', 'orders.required_delivery_date', 'orders.is_urgent',
                'orders.total_quantity', 'orders.total_minor', 'orders.verified_paid_minor', 'orders.currency']);

        match ($report) {
            'overdue-jobs' => $query->whereIn('orders.status', self::activeStatuses())->where('orders.required_delivery_date', '<', $today)
                ->orderBy('orders.required_delivery_date')->orderBy('orders.id'),
            'upcoming-due' => $query->whereIn('orders.status', self::activeStatuses())
                ->whereBetween('orders.required_delivery_date', [$today, BusinessDate::today()->addDays($filters['days'])->toDateString()])
                ->orderBy('orders.required_delivery_date')->orderByDesc('orders.is_urgent')->orderBy('orders.id'),
            'outstanding-balance' => $query->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value])
                ->whereColumn('orders.total_minor', '>', 'orders.verified_paid_minor')
                ->orderByRaw('(orders.total_minor - orders.verified_paid_minor) DESC')->orderBy('orders.id'),
            default => throw new \InvalidArgumentException("Unknown list report {$report}"),
        };

        return $query->paginate($perPage);
    }

    /** Totals of the outstanding-balance report (cached like the aggregates). */
    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    public function outstandingTotals(User $user, array $filters): array
    {
        $key = 'report:outstanding-totals:'.ReportScope::fingerprint($user).':'.md5(json_encode($filters, JSON_THROW_ON_ERROR));

        return ReportScope::remember($key, self::CACHE_SECONDS, fn (): array => $this->orders($user, $filters, dated: false)
            ->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value])
            ->whereColumn('orders.total_minor', '>', 'orders.verified_paid_minor')
            ->selectRaw('orders.currency AS currency, COUNT(*) AS jobs, SUM(orders.total_minor - orders.verified_paid_minor) AS outstanding_minor')
            ->groupBy('orders.currency')->get()
            ->map(fn ($r) => ['currency' => $r->getAttribute('currency'), 'jobs' => (int) $r->getAttribute('jobs'), 'outstanding' => self::money((int) $r->getAttribute('outstanding_minor'), (string) $r->getAttribute('currency'))])->all());
    }

    /**
     * Orders in scope, optionally limited to the created-date range (Colombo business dates).
     *
     * @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters
     * @return Builder<Order>
     */
    private function orders(User $user, array $filters, bool $dated = true): Builder
    {
        return Order::query()->visibleTo($user)
            ->when($filters['branch_id'] !== null, fn ($q) => $q->where('orders.branch_id', $filters['branch_id']))
            ->when($filters['page_id'] !== null, fn ($q) => $q->where('orders.page_id', $filters['page_id']))
            ->when($dated, fn ($q) => $q->whereBetween('orders.created_at', self::range($filters)));
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    private function jobsByStatus(User $user, array $filters): array
    {
        $rows = $this->orders($user, $filters)->where('orders.status', '!=', OrderStatus::Draft->value)
            ->select('orders.status', DB::raw('COUNT(*) AS total'))->groupBy('orders.status')->pluck('total', 'status');

        return ['rows' => collect(OrderStatus::cases())->filter(fn ($s) => isset($rows[$s->value]))
            ->map(fn (OrderStatus $s) => ['status' => $s->value, 'label' => $s->label(), 'total' => (int) $rows[$s->value]])->values()->all(),
            'total' => (int) $rows->sum()];
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    private function jobsByBranchPage(User $user, array $filters): array
    {
        $active = self::activeStatuses();
        $rows = $this->orders($user, $filters)->where('orders.status', '!=', OrderStatus::Draft->value)
            ->join('branches', 'branches.id', '=', 'orders.branch_id')->join('pages', 'pages.id', '=', 'orders.page_id')
            ->selectRaw('branches.code AS branch, pages.code AS page, orders.currency AS currency, COUNT(*) AS jobs, '
                .'SUM(CASE WHEN orders.status IN ('.implode(',', array_fill(0, count($active), '?')).') THEN 1 ELSE 0 END) AS active, '
                ."SUM(CASE WHEN orders.status = 'completed' THEN 1 ELSE 0 END) AS completed, SUM(orders.total_minor) AS value_minor", $active)
            ->groupBy('branches.code', 'pages.code', 'orders.currency')->orderBy('branches.code')->orderBy('pages.code')->get();

        return ['rows' => $rows->map(fn ($r) => ['branch' => $r->getAttribute('branch'), 'page' => $r->getAttribute('page'), 'jobs' => (int) $r->getAttribute('jobs'),
            'active' => (int) $r->getAttribute('active'), 'completed' => (int) $r->getAttribute('completed'),
            'value' => self::money((int) $r->getAttribute('value_minor'), (string) $r->getAttribute('currency'))])->all()];
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    private function paymentStatus(User $user, array $filters): array
    {
        $bucket = 'CASE WHEN orders.verified_paid_minor <= 0 THEN \'no_verified_payment\' WHEN orders.verified_paid_minor < orders.advance_required_minor THEN \'advance_short\' '
            .'WHEN orders.verified_paid_minor < orders.total_minor THEN \'advance_met\' ELSE \'paid_in_full\' END';
        $rows = $this->orders($user, $filters)->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value])
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS jobs, SUM(orders.total_minor) AS total_minor, SUM(orders.verified_paid_minor) AS paid_minor")
            ->groupByRaw($bucket)->get()->keyBy('bucket');
        $labels = ['no_verified_payment' => 'No verified payment', 'advance_short' => 'Advance below required', 'advance_met' => 'Advance met, balance due', 'paid_in_full' => 'Paid in full'];

        return ['rows' => collect($labels)->map(fn (string $label, string $key) => [
            'bucket' => $key, 'label' => $label, 'jobs' => (int) ($rows->get($key)?->getAttribute('jobs') ?? 0),
            'total_minor' => (int) ($rows->get($key)?->getAttribute('total_minor') ?? 0), 'verified_minor' => (int) ($rows->get($key)?->getAttribute('paid_minor') ?? 0),
        ])->values()->all()];
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    private function advanceCollection(User $user, array $filters): array
    {
        $rows = $this->orders($user, $filters)->whereNotIn('orders.status', [OrderStatus::Draft->value, OrderStatus::Cancelled->value])
            ->join('pages', 'pages.id', '=', 'orders.page_id')
            ->selectRaw('pages.code AS page, orders.currency AS currency, COUNT(*) AS jobs, SUM(orders.advance_required_minor) AS required_minor, '
                .'SUM(CASE WHEN orders.verified_paid_minor >= orders.advance_required_minor THEN 1 ELSE 0 END) AS met, '
                .'SUM(CASE WHEN orders.verified_paid_minor < orders.advance_required_minor THEN orders.verified_paid_minor ELSE orders.advance_required_minor END) AS collected_minor, '
                .'SUM(CASE WHEN orders.advance_required_minor > orders.verified_paid_minor THEN orders.advance_required_minor - orders.verified_paid_minor ELSE 0 END) AS shortfall_minor')
            ->groupBy('pages.code', 'orders.currency')->orderBy('pages.code')->get();

        return ['rows' => $rows->map(function ($r): array {
            $c = (string) $r->getAttribute('currency');

            return ['page' => $r->getAttribute('page'), 'jobs' => (int) $r->getAttribute('jobs'), 'advance_met' => (int) $r->getAttribute('met'),
                'required' => self::money((int) $r->getAttribute('required_minor'), $c), 'collected' => self::money((int) $r->getAttribute('collected_minor'), $c),
                'shortfall' => self::money((int) $r->getAttribute('shortfall_minor'), $c)];
        })->all()];
    }

    /**
     * Average / fastest / slowest time from creation to completion of a stage record, overall and
     * per branch, for records COMPLETED within the date range.
     *
     * @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters
     */
    private function duration(User $user, array $filters, string $table, string $start, string $end, ?string $statusColumn, ?string $doneStatus): array
    {
        $seconds = ReportScope::seconds($start, $end);
        $base = fn (): QueryBuilder => DB::table($table)->join('orders', 'orders.id', '=', "{$table}.order_id")
            ->whereIn('orders.id', $this->orders($user, $filters, dated: false)->select('orders.id'))
            ->whereNotNull($end)->whereBetween($end, self::range($filters))
            ->when($statusColumn !== null, fn ($q) => $q->where($statusColumn, $doneStatus));
        $row = $base()->selectRaw("COUNT(*) AS n, AVG({$seconds}) AS avg_s, MIN({$seconds}) AS min_s, MAX({$seconds}) AS max_s")->first();
        $byBranch = $base()->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->selectRaw("branches.code AS branch, COUNT(*) AS n, AVG({$seconds}) AS avg_s")->groupBy('branches.code')->orderBy('branches.code')->get();
        $hours = fn (mixed $s): ?float => $s === null ? null : round((float) $s / 3600, 1);

        return [
            'summary' => ['completed' => (int) ($row->n ?? 0), 'average_hours' => $hours($row->avg_s ?? null), 'fastest_hours' => $hours($row->min_s ?? null), 'slowest_hours' => $hours($row->max_s ?? null)],
            'rows' => $byBranch->map(fn ($r) => ['branch' => $r->branch, 'completed' => (int) $r->n, 'average_hours' => $hours($r->avg_s)])->all(),
        ];
    }

    /** @param  array{from: string, to: string, branch_id: int|null, page_id: int|null, days: int}  $filters */
    private function issueSummary(User $user, array $filters): array
    {
        $base = fn (): QueryBuilder => DB::table('production_issues')->whereIn('order_id', $this->orders($user, $filters, dated: false)->select('orders.id'))
            ->whereBetween('production_issues.created_at', self::range($filters));
        $unresolved = IssueStatus::unresolvedValues();
        $rows = $base()->selectRaw('type, COUNT(*) AS total, SUM(CASE WHEN status IN ('.implode(',', array_fill(0, count($unresolved), '?')).') THEN 1 ELSE 0 END) AS open_count, '
            .'SUM(CASE WHEN is_blocking = 1 AND status IN ('.implode(',', array_fill(0, count($unresolved), '?')).') THEN 1 ELSE 0 END) AS blocking, SUM(COALESCE(quantity_affected, 0)) AS pieces', [...$unresolved, ...$unresolved])
            ->groupBy('type')->get();
        $resolution = $base()->whereNotNull('resolved_at')->selectRaw('AVG('.ReportScope::seconds('production_issues.created_at', 'production_issues.resolved_at').') AS s')->value('s');

        return [
            'rows' => $rows->map(fn ($r) => ['category' => $r->type, 'label' => IssueType::tryFrom((string) $r->type)?->label() ?? (string) $r->type, 'total' => (int) $r->total,
                'unresolved' => (int) $r->open_count, 'blocking' => (int) $r->blocking, 'pieces_affected' => (int) $r->pieces])->sortByDesc('total')->values()->all(),
            'by_status' => $base()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all(),
            'average_resolution_hours' => $resolution === null ? null : round((float) $resolution / 3600, 1),
        ];
    }

    /** @return list<string> */
    public static function activeStatuses(): array
    {
        return array_values(array_map(fn (OrderStatus $s) => $s->value, array_filter(OrderStatus::released(), fn (OrderStatus $s) => $s->isActive())));
    }

    /**
     * UTC bounds of the Colombo business-date range.
     *
     * @param  array{from: string, to: string}  $filters
     * @return array{0: string, 1: string}
     */
    private static function range(array $filters): array
    {
        return [BusinessDate::parse($filters['from'])->utc()->toDateTimeString(), BusinessDate::parse($filters['to'])->endOfDay()->utc()->toDateTimeString()];
    }

    /** @return array{amount_minor: int, formatted: string} */
    public static function money(int $minor, string $currency): array
    {
        return ['amount_minor' => $minor, 'formatted' => $currency.' '.number_format($minor / 100, 2)];
    }
}
