<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Controllers;

use App\Modules\BulkProduction\Actions\ManageProductionIssue;
use App\Modules\BulkProduction\Actions\ReportProductionIssue;
use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\BulkProduction\Http\Resources\ProductionIssueResource;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\Cutting\Enums\GarmentPart;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Documents\Enums\DocumentCategory;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Rules\AllowedDocument;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Models\Order;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central production issue register (Phase 07).
 *
 * The list shows every issue in scope to production.view / production.issues.manage holders;
 * other users see the issues they reported, are assigned to, raised on their cutting jobs, or
 * (Bulk Designers) bulk-related issues of their bulk jobs — the same rule as the policy.
 */
final class ProductionIssueController
{
    private const SORTS = ['created_at', '-created_at', 'status', '-status'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ProductionIssue::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.category' => ['sometimes', 'string', 'max:300'],
            'filter.order_id' => ['sometimes', 'integer'],
            'filter.cutting_job_id' => ['sometimes', 'integer'],
            'filter.unresolved' => ['sometimes', 'boolean'],
            'filter.blocking' => ['sometimes', 'boolean'],
            'filter.mine' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        $query = ProductionIssue::query()->visibleTo($user)
            ->join('orders', 'orders.id', '=', 'production_issues.order_id')
            ->select('production_issues.*')
            ->with(self::relations());

        if (! $user->can(Permissions::PRODUCTION_VIEW) && ! $user->can(Permissions::ISSUES_MANAGE)) {
            $query->where(fn (Builder $q) => $q->where('production_issues.raised_by', $user->id)
                ->orWhere('production_issues.assigned_to', $user->id)
                ->orWhereIn('production_issues.cutting_job_id', CuttingJob::query()->select('id')->where('supervisor_id', $user->id))
                ->orWhere(fn (Builder $b) => $b->whereIn('production_issues.type', self::bulkRelated())
                    ->whereIn('production_issues.order_id', BulkJob::query()->select('order_id')->where('bulk_designer_id', $user->id))));
        }

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => IssueStatus::tryFrom($s) !== null));
            $query->whereIn('production_issues.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->filled('filter.category')) {
            $types = array_values(array_filter(explode(',', (string) $request->input('filter.category')), fn ($s) => IssueType::tryFrom($s) !== null));
            $query->whereIn('production_issues.type', $types === [] ? ['-'] : $types);
        }
        if ($request->filled('filter.order_id')) {
            $query->where('production_issues.order_id', (int) $request->input('filter.order_id'));
        }
        if ($request->filled('filter.cutting_job_id')) {
            $query->where('production_issues.cutting_job_id', (int) $request->input('filter.cutting_job_id'));
        }
        if ($request->boolean('filter.unresolved')) {
            $query->whereIn('production_issues.status', IssueStatus::unresolvedValues());
        }
        if ($request->boolean('filter.blocking')) {
            $query->blocking();
        }
        if ($request->boolean('filter.mine')) {
            $query->where('production_issues.assigned_to', $user->id);
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)->orWhere('production_issues.description', 'like', $like));
        }

        $sort = (string) $request->input('sort', '-created_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $query->orderBy('production_issues.'.ltrim($sort, '-'), $direction)->orderBy('production_issues.id', $direction);

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), ProductionIssueResource::class);
    }

    public function show(ProductionIssue $issue): ProductionIssueResource
    {
        Gate::authorize('view', $issue);

        return new ProductionIssueResource(self::loadDetail($issue));
    }

    /** Report an issue on an order (issues raised on a cutting job use POST /cutting-jobs/{id}/issues). */
    public function store(Request $request, ReportProductionIssue $action): JsonResponse
    {
        $request->validate(['order_id' => ['required', 'integer']]);
        $order = Order::query()->find((int) $request->input('order_id'));
        abort_if($order === null, 404);
        Gate::authorize('reportIssue', $order);
        $data = $request->validate(self::reportRules());
        /** @var User $actor */
        $actor = $request->user();
        $issue = $action->handle($actor, $order, null, self::reportData($data), $request->file('image'));

        return (new ProductionIssueResource(self::loadDetail($issue)))->response()->setStatusCode(201);
    }

    /** Assign the issue / change the blocking flag (issue managers). */
    public function update(Request $request, ProductionIssue $issue, ManageProductionIssue $action): ProductionIssueResource
    {
        Gate::authorize('manage', $issue);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'is_blocking' => ['sometimes', 'boolean'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $action->update($actor, $issue, (int) $data['version'], isset($data['assigned_to']) ? (int) $data['assigned_to'] : null, array_key_exists('assigned_to', $data),
            array_key_exists('is_blocking', $data) ? (bool) $data['is_blocking'] : null);

        return new ProductionIssueResource(self::loadDetail($issue->refresh()));
    }

    /** { to, version, note?, resolution? } — resolution required for `resolved`, note for reopening. */
    public function transition(Request $request, ProductionIssue $issue, ManageProductionIssue $action): ProductionIssueResource
    {
        Gate::authorize('transition', $issue);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'to' => ['required', Rule::enum(IssueStatus::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'resolution' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $action->transition($actor, $issue, (int) $data['version'], IssueStatus::from($data['to']), self::text($data['note'] ?? null), self::text($data['resolution'] ?? null));

        return new ProductionIssueResource(self::loadDetail($issue->refresh()));
    }

    /** Users the issue can be assigned to (active, in scope, can report or manage issues). */
    public function assignees(ProductionIssue $issue): JsonResponse
    {
        Gate::authorize('manage', $issue);
        $order = Order::query()->select(['id', 'branch_id', 'page_id'])->findOrFail($issue->getAttribute('order_id'));
        $users = User::query()->where('is_active', true)->permission([Permissions::ISSUES_REPORT, Permissions::ISSUES_MANAGE])->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all();

        return new JsonResponse(['data' => $users]);
    }

    /** The issue image: signed short-lived URL AND the issue policy. */
    public function image(ProductionIssue $issue): StreamedResponse
    {
        Gate::authorize('view', $issue);
        $file = $issue->getAttribute('image_document_id') === null ? null : Document::query()->find($issue->getAttribute('image_document_id'));
        abort_if($file === null, 404);

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], 'inline');
    }

    /** @return array<string, list<mixed>> */
    public static function reportRules(): array
    {
        return [
            'category' => ['required', Rule::enum(IssueType::class)],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'quantity_affected' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'part' => ['sometimes', 'nullable', Rule::enum(GarmentPart::class)],
            'size' => ['sometimes', 'nullable', 'string', 'max:60'],
            'is_blocking' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'file', new AllowedDocument(DocumentCategory::IssuePhoto)],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: IssueType, description: string, quantity_affected: int|null, part: GarmentPart|null, size_label: string|null, is_blocking: bool|null}
     */
    public static function reportData(array $data): array
    {
        return [
            'type' => IssueType::from((string) $data['category']),
            'description' => trim((string) $data['description']),
            'quantity_affected' => isset($data['quantity_affected']) ? (int) $data['quantity_affected'] : null,
            'part' => isset($data['part']) ? GarmentPart::from((string) $data['part']) : null,
            'size_label' => self::text($data['size'] ?? null),
            'is_blocking' => array_key_exists('is_blocking', $data) ? (bool) $data['is_blocking'] : null,
        ];
    }

    /** @return list<string> */
    private static function bulkRelated(): array
    {
        return array_values(array_map(fn (IssueType $t) => $t->value, array_filter(IssueType::cases(), fn (IssueType $t) => $t->isBulkRelated())));
    }

    private static function text(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        return $value === '' ? null : $value;
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'status']),
            'order.page:id,code,name', 'order.customer:id,name', 'branch:id,code',
            'raiser:id,name', 'assignee:id,name',
        ];
    }

    public static function loadDetail(ProductionIssue $issue): ProductionIssue
    {
        return $issue->load([...self::relations(), 'resolver:id,name', 'closer:id,name', 'events' => fn ($q) => $q->orderByDesc('id')->with('actor:id,name')]);
    }
}
