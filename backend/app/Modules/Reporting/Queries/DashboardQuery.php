<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Queries;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ChecklistResult;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Delivery\Enums\DeliveryStatus;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Garments\Enums\GarmentListVersionStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions as P;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\Payment;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Reporting\Support\ReportScope;
use App\Support\Time\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Role-specific dashboards (Phase 08 §5). Sections are chosen by PERMISSION (never by role
 * name), so a custom role gets the sections that match what it can do. Every figure is
 * restricted to the user's data scope (ReportScope::orderIds) and, for "assigned only" roles,
 * to the records assigned to them. Indexed COUNT queries; each section is cached for 60 s
 * per user (CACHE_SECONDS), so polling dashboards never recompute on every request.
 */
final class DashboardQuery
{
    public const CACHE_SECONDS = 60;

    /** @return list<array{key: string, title: string, metrics: list<array<string, mixed>>, items?: list<array<string, mixed>>}> */
    public function sections(User $user): array
    {
        $sections = [];
        $add = function (string $key, bool $allowed, callable $build) use (&$sections, $user): void {
            if ($allowed) {
                $sections[] = ReportScope::remember("dashboard:{$key}:".ReportScope::fingerprint($user, true), self::CACHE_SECONDS, $build);
            }
        };

        $add('admin', $user->can(P::USERS_VIEW) && $user->can(P::REPORTS_VIEW), fn () => $this->admin($user));
        $add('page_owner', $user->can(P::DESIGNS_APPROVE) || $user->can(P::BULK_APPROVE) || $user->can(P::PRINTING_APPROVE_SAMPLE), fn () => $this->pageOwner($user));
        $add('designer', $user->can(P::DESIGNS_VIEW_ASSIGNED), fn () => $this->designer($user));
        $add('bulk_designer', $user->can(P::BULK_VIEW_ASSIGNED), fn () => $this->bulkDesigner($user));
        $add('operations', $user->can(P::BULK_ASSIGN) || $user->can(P::PRINTING_MANAGE) || $user->can(P::CUTTING_MANAGE), fn () => $this->operations($user));
        $add('cutting', $user->can(P::CUTTING_VIEW_ASSIGNED), fn () => $this->cutting($user));
        $add('delivery', $user->can(P::DELIVERY_VIEW), fn () => $this->delivery($user));

        return $sections;
    }

    /** @return array<string, mixed> */
    private function admin(User $user): array
    {
        $orders = fn () => Order::query()->visibleTo($user)->where('orders.status', '!=', OrderStatus::Draft->value);
        $since = now()->subDays(30);
        $byStatus = $orders()->select('orders.status', DB::raw('COUNT(*) AS total'))->groupBy('orders.status')->pluck('total', 'status');
        $active = array_values(array_filter(OrderStatus::released(), fn (OrderStatus $s) => $s->isActive()));
        $avgSeconds = $orders()->where('orders.status', OrderStatus::Completed->value)->where('orders.completed_at', '>=', now()->subDays(90))
            ->selectRaw('AVG('.ReportScope::seconds('orders.created_at', 'orders.completed_at').') AS s')->value('s');

        return ['key' => 'admin', 'title' => 'Company overview', 'metrics' => [
            self::metric('total_jobs', 'Total jobs', (int) $byStatus->sum()),
            self::metric('active_jobs', 'Active jobs', (int) $byStatus->only(array_map(fn ($s) => $s->value, $active))->sum(), '/orders/'),
            self::metric('active_users', 'Active users', User::query()->where('is_active', true)->count(), '/settings/users/'),
            self::metric('completed_30d', 'Completed (30 days)', $orders()->where('orders.status', OrderStatus::Completed->value)->where('orders.completed_at', '>=', $since)->count()),
            self::metric('overdue', 'Overdue jobs', $this->overdue($user), '/reports/?report=overdue-jobs'),
            self::metric('avg_cycle_days', 'Average job cycle (days, 90 days)', $avgSeconds === null ? null : round((float) $avgSeconds / 86400, 1)),
        ], 'jobs_by_status' => collect(OrderStatus::cases())->filter(fn ($s) => (int) ($byStatus[$s->value] ?? 0) > 0)
            ->map(fn (OrderStatus $s) => ['status' => $s->value, 'label' => $s->label(), 'total' => (int) $byStatus[$s->value]])->values()->all(),
            'branches' => $orders()->join('branches', 'branches.id', '=', 'orders.branch_id')->join('pages', 'pages.id', '=', 'orders.page_id')
                ->whereIn('orders.status', array_map(fn ($s) => $s->value, $active))
                ->select('branches.code AS branch', 'pages.code AS page', DB::raw('COUNT(*) AS active_jobs'))
                ->groupBy('branches.code', 'pages.code')->orderBy('branches.code')->orderBy('pages.code')->get()
                ->map(fn ($r) => ['branch' => $r->getAttribute('branch'), 'page' => $r->getAttribute('page'), 'active_jobs' => (int) $r->getAttribute('active_jobs')])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function pageOwner(User $user): array
    {
        $scope = ReportScope::orderIds($user);
        $listApprovals = DB::table('garment_list_versions AS v')
            ->join('garment_lists AS l', 'l.current_version_id', '=', 'v.id')
            ->join('order_items AS i', 'i.id', '=', 'l.order_item_id')
            ->join('orders AS o', 'o.id', '=', 'i.order_id')
            ->where('v.status', GarmentListVersionStatus::Draft->value)
            ->whereIn('o.status', [OrderStatus::Confirmed->value, OrderStatus::DesignInProgress->value, OrderStatus::DesignReview->value, OrderStatus::DesignApproved->value])
            ->whereIn('o.id', $scope)->count();

        return ['key' => 'page_owner', 'title' => 'My pages', 'metrics' => [
            self::metric('new_orders', 'New orders (7 days)', Order::query()->visibleTo($user)->where('orders.status', '!=', OrderStatus::Draft->value)->where('orders.created_at', '>=', now()->subDays(7))->count(), '/orders/'),
            self::metric('pending_payments', 'Payments awaiting verification', Payment::query()->visibleTo($user)->where('payments.status', PaymentStatus::PendingVerification->value)->count(), '/payments/'),
            self::metric('design_approvals', 'Design approvals', Order::query()->visibleTo($user)->where('orders.status', OrderStatus::DesignReview->value)->count(), '/designs/'),
            self::metric('list_approvals', 'Lists to confirm', $listApprovals, '/orders/approved/'),
            self::metric('bulk_approvals', 'Bulk approvals', BulkJob::query()->whereIn('order_id', $scope)->where('status', BulkJobStatus::InReview->value)->count(), '/bulk-jobs/'),
            self::metric('sample_approvals', 'Printing sample approvals', PrintJob::query()->whereIn('order_id', $scope)->where('status', PrintJobStatus::SamplePendingApproval->value)->count(), '/printing/'),
        ], 'items' => $this->upcoming($user)];
    }

    /** @return array<string, mixed> */
    private function designer(User $user): array
    {
        $mine = fn () => DesignTask::query()->where('designer_id', $user->id)->whereIn('order_id', ReportScope::orderIds($user));
        $due = $mine()->where('design_tasks.status', '!=', DesignTaskStatus::Completed->value)
            ->join('orders', 'orders.id', '=', 'design_tasks.order_id')
            ->orderBy('orders.required_delivery_date')->orderByDesc('orders.is_urgent')->limit(8)
            ->get(['design_tasks.id', 'design_tasks.status', 'orders.order_no', 'orders.required_delivery_date', 'orders.is_urgent']);

        return ['key' => 'designer', 'title' => 'My design tasks', 'metrics' => [
            self::metric('assigned', 'Assigned (open)', $mine()->where('status', '!=', DesignTaskStatus::Completed->value)->count(), '/designs/'),
            self::metric('pending', 'Pending design work', $mine()->whereIn('status', [DesignTaskStatus::Pending->value, DesignTaskStatus::InProgress->value])->count(), '/designs/'),
            self::metric('corrections', 'Corrections requested', $mine()->where('status', DesignTaskStatus::ChangesRequested->value)->count(), '/designs/'),
            self::metric('final_files', 'Final files due', $mine()->where('status', DesignTaskStatus::Approved->value)->count(), '/designs/'),
        ], 'items' => $due->map(fn ($t) => ['label' => $t->getAttribute('order_no'), 'due_date' => self::date($t->getAttribute('required_delivery_date')),
            'is_urgent' => (bool) $t->getAttribute('is_urgent'), 'status' => $t->status->value, 'link' => "/designs/view/?id={$t->id}"])->all()];
    }

    /** @return array<string, mixed> */
    private function bulkDesigner(User $user): array
    {
        $open = fn () => BulkJob::query()->where('bulk_designer_id', $user->id)->whereIn('order_id', ReportScope::orderIds($user))
            ->whereNotIn('status', [BulkJobStatus::Completed->value, BulkJobStatus::Cancelled->value]);
        $openCount = $open()->count();
        $systemFields = DB::table('bulk_measurement_fields')->where('is_system', true)->where('is_active', true)->pluck('id');
        $measured = $systemFields->isEmpty() ? $openCount : $open()->whereRaw('(SELECT COUNT(DISTINCT m.bulk_measurement_field_id) FROM bulk_job_measurements m WHERE m.bulk_job_id = bulk_jobs.id AND m.bulk_measurement_field_id IN ('
            .implode(',', $systemFields->map(fn ($id) => (int) $id)->all()).')) = ?', [$systemFields->count()])->count();

        return ['key' => 'bulk_designer', 'title' => 'My bulk jobs', 'metrics' => [
            self::metric('assigned', 'Assigned (open)', $openCount, '/bulk-jobs/mine/'),
            self::metric('corrections', 'Pending corrections', $open()->where('status', BulkJobStatus::ChangesRequested->value)->count(), '/bulk-jobs/mine/'),
            self::metric('measurements', 'Measurements complete', "{$measured} / {$openCount}"),
            self::metric('submissions', 'Pending submissions', $open()->whereIn('status', [BulkJobStatus::Assigned->value, BulkJobStatus::InProgress->value, BulkJobStatus::ChangesRequested->value, BulkJobStatus::Approved->value])->count(), '/bulk-jobs/mine/'),
        ]];
    }

    /** @return array<string, mixed> */
    private function operations(User $user): array
    {
        $scope = ReportScope::orderIds($user);
        $printing = fn (array $statuses) => PrintJob::query()->whereIn('order_id', $scope)->whereIn('status', array_map(fn ($s) => $s->value, $statuses))->count();

        return ['key' => 'operations', 'title' => 'Operations', 'metrics' => [
            self::metric('bulk_unassigned', 'Bulk jobs to assign', BulkJob::query()->whereIn('order_id', $scope)->where('status', BulkJobStatus::PendingAssignment->value)->count(), '/bulk-jobs/'),
            self::metric('printing_ready', 'Ready for printing', $printing([PrintJobStatus::ReadyForPrinting]), '/printing/'),
            self::metric('printing_in_progress', 'Printing in progress', $printing([PrintJobStatus::SentForPrinting, PrintJobStatus::SampleRejected, PrintJobStatus::SampleApproved, PrintJobStatus::PhysicalPrinting]), '/printing/'),
            self::metric('sample_approvals', 'Samples awaiting approval', $printing([PrintJobStatus::SamplePendingApproval]), '/printing/'),
            self::metric('cutting_open', 'Cutting jobs (open)', CuttingJob::query()->whereIn('order_id', $scope)->where('status', '!=', CuttingJobStatus::Completed->value)->count(), '/cutting/'),
            self::metric('cutting_unassigned', 'Cutting to assign', CuttingJob::query()->whereIn('order_id', $scope)->where('status', CuttingJobStatus::PendingAssignment->value)->count(), '/cutting/'),
            self::metric('open_issues', 'Open production issues', ProductionIssue::query()->whereIn('order_id', $scope)->whereIn('status', IssueStatus::unresolvedValues())->count(), '/issues/'),
            self::metric('blocking_issues', 'Blocking issues', ProductionIssue::query()->whereIn('order_id', $scope)->blocking()->count(), '/issues/'),
        ]];
    }

    /** @return array<string, mixed> */
    private function cutting(User $user): array
    {
        $mine = fn () => CuttingJob::query()->where('supervisor_id', $user->id)->whereIn('order_id', ReportScope::orderIds($user))->where('status', '!=', CuttingJobStatus::Completed->value);
        $runIds = fn () => DB::table('checklist_runs')->whereIn('cutting_job_id', $mine()->select('id'))->whereNull('superseded_at');
        $pendingTasks = ChecklistRunItem::query()->whereIn('checklist_run_id', $runIds()->where('stage', ChecklistStage::Cutting->value)->select('id'))->where('is_completed', false)->count();
        $pendingChecks = ChecklistRunItem::query()->whereIn('checklist_run_id', $runIds()->where('stage', ChecklistStage::QualityControl->value)->select('id'))
            ->where(fn ($q) => $q->whereNull('result')->orWhere('result', ChecklistResult::Fail->value))->count();

        return ['key' => 'cutting', 'title' => 'My cutting work', 'metrics' => [
            self::metric('assigned', 'Assigned cutting tasks', $mine()->count(), '/cutting/'),
            self::metric('pending_items', 'Pending checklist items', $pendingTasks),
            self::metric('pending_checks', 'Quality checks to pass', $pendingChecks),
            self::metric('reported_issues', 'My unresolved issues', ProductionIssue::query()->where('raised_by', $user->id)->whereIn('status', IssueStatus::unresolvedValues())->count(), '/issues/'),
        ]];
    }

    /** @return array<string, mixed> */
    private function delivery(User $user): array
    {
        $deliveries = fn () => Delivery::query()->visibleTo($user);

        return ['key' => 'delivery', 'title' => 'Deliveries', 'metrics' => [
            self::metric('ready', 'Ready for delivery', $deliveries()->where('deliveries.status', DeliveryStatus::Ready->value)->count(), '/deliveries/'),
            self::metric('out', 'Out for delivery', $deliveries()->where('deliveries.status', DeliveryStatus::Dispatched->value)->count(), '/deliveries/'),
            self::metric('missing_documents', 'Pending delivery documents', $deliveries()->missingDocuments()->count(), '/deliveries/?missing=1'),
            self::metric('completed_30d', 'Delivered (30 days)', $deliveries()->where('deliveries.status', DeliveryStatus::Delivered->value)->where('deliveries.delivered_at', '>=', now()->subDays(30))->count()),
        ]];
    }

    private function overdue(User $user): int
    {
        return Order::query()->visibleTo($user)->whereIn('orders.status', array_map(fn ($s) => $s->value, array_filter(OrderStatus::released(), fn ($s) => $s->isActive())))
            ->where('orders.required_delivery_date', '<', BusinessDate::today()->toDateString())->count();
    }

    /** @return list<array<string, mixed>> active jobs due in the next 14 days (Colombo dates) */
    private function upcoming(User $user): array
    {
        $today = BusinessDate::today();

        return Order::query()->visibleTo($user)->whereIn('orders.status', array_map(fn ($s) => $s->value, array_filter(OrderStatus::released(), fn ($s) => $s->isActive())))
            ->whereBetween('orders.required_delivery_date', [$today->toDateString(), $today->addDays(14)->toDateString()])
            ->orderBy('orders.required_delivery_date')->orderByDesc('orders.is_urgent')->limit(8)
            ->get(['orders.id', 'orders.order_no', 'orders.status', 'orders.required_delivery_date', 'orders.is_urgent'])
            ->map(fn (Order $o) => ['label' => $o->order_no, 'due_date' => $o->required_delivery_date->format('Y-m-d'), 'is_urgent' => $o->is_urgent,
                'status' => $o->status->label(), 'link' => "/orders/view/?id={$o->id}"])->values()->all();
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /** @return array{key: string, label: string, value: int|float|string|null, link: string|null} */
    private static function metric(string $key, string $label, int|float|string|null $value, ?string $link = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'link' => $link];
    }
}
