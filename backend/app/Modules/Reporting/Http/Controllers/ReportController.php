<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Reporting\Queries\DashboardQuery;
use App\Modules\Reporting\Queries\ReportQuery;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use App\Support\Time\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Role-specific dashboard (any signed-in user; sections follow the user's permissions) and the
 * operational reports (reports.view). All figures are limited to the caller's branch/page scope.
 */
final class ReportController
{
    public function dashboard(Request $request, DashboardQuery $dashboard): JsonResponse
    {
        Gate::authorize('dashboard-view');

        return ApiResponse::data(['sections' => $dashboard->sections($this->user($request)), 'refresh_seconds' => DashboardQuery::CACHE_SECONDS]);
    }

    public function catalogue(): JsonResponse
    {
        Gate::authorize('reports-access');

        return ApiResponse::data(['reports' => collect(ReportQuery::REPORTS)->map(fn (array $r, string $key) => ['key' => $key, ...$r])->values()->all()]);
    }

    public function show(Request $request, string $report, ReportQuery $reports): JsonResponse
    {
        Gate::authorize('reports-access');
        abort_unless(array_key_exists($report, ReportQuery::REPORTS), 404);
        $data = $request->validate([
            'filter.from' => ['sometimes', 'date_format:Y-m-d'],
            'filter.to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:filter.from'],
            'filter.branch_id' => ['sometimes', 'integer'],
            'filter.page_id' => ['sometimes', 'integer'],
            'filter.days' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);
        $user = $this->user($request);
        $today = BusinessDate::today();
        $filters = [
            'from' => (string) ($data['filter']['from'] ?? $today->subDays(89)->toDateString()),
            'to' => (string) ($data['filter']['to'] ?? $today->toDateString()),
            'branch_id' => isset($data['filter']['branch_id']) ? (int) $data['filter']['branch_id'] : null,
            'page_id' => isset($data['filter']['page_id']) ? (int) $data['filter']['page_id'] : null,
            'days' => (int) ($data['filter']['days'] ?? 14),
        ];
        $meta = ['key' => $report, 'title' => ReportQuery::REPORTS[$report]['title'], 'filters' => $filters];

        if (! ReportQuery::REPORTS[$report]['paginated']) {
            return ApiResponse::data([...$meta, ...$reports->aggregate($report, $user, $filters)]);
        }

        $page = $reports->paginate($report, $user, $filters, ListQuery::perPage($request))->withQueryString();

        return new JsonResponse([
            'data' => [...$meta,
                'totals' => $report === 'outstanding-balance' ? $reports->outstandingTotals($user, $filters) : null,
                'rows' => collect($page->items())->map(fn (Order $o) => [
                    'id' => $o->id, 'job_no' => $o->order_no, 'customer' => $o->customer->name, 'branch' => $o->branch->code, 'page' => $o->page->code,
                    'status' => ['value' => $o->status->value, 'label' => $o->status->label()], 'due_date' => $o->required_delivery_date->format('Y-m-d'),
                    'is_urgent' => $o->is_urgent, 'quantity' => $o->total_quantity,
                    'total' => ReportQuery::money($o->total_minor, $o->currency), 'verified' => ReportQuery::money($o->verified_paid_minor, $o->currency),
                    'outstanding' => ReportQuery::money(max(0, $o->total_minor - $o->verified_paid_minor), $o->currency),
                    'days_overdue' => max(0, (int) BusinessDate::parse($o->required_delivery_date->format('Y-m-d'))->diffInDays($today, false)),
                ])->all()],
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
