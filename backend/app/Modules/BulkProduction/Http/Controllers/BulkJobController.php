<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Controllers;

use App\Modules\BulkProduction\Actions\AssignBulkJob;
use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Http\Resources\BulkJobResource;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\BulkProduction\Queries\BulkDocuments;
use App\Modules\BulkProduction\Queries\ConfirmedGarmentList;
use App\Modules\BulkProduction\Queries\JobCard;
use App\Modules\Customers\Models\Customer;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentCsv;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
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
 * Bulk dashboards and read endpoints.
 *
 *  - Operation Admin bulk management dashboard: every job in scope (bulk.view).
 *  - Bulk Designer dashboard: only the jobs assigned to me (bulk.view_assigned).
 *
 * Both are the same list endpoint; the data scope comes from the user's permissions. Default order:
 * earliest due date first, urgent before normal on the same day, then oldest.
 */
final class BulkJobController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', BulkJob::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.mode' => ['sometimes', Rule::in([...ProductionMode::values(), 'unassigned'])],
            'filter.open' => ['sometimes', 'boolean'],
            'filter.mine' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['due_date', '-due_date', 'created_at', '-created_at'])],
        ]);
        /** @var User $user */
        $user = $request->user();

        $query = BulkJob::query()->accessibleTo($user, Permissions::BULK_VIEW, Permissions::BULK_VIEW_ASSIGNED, 'bulk_designer_id')
            ->join('orders', 'orders.id', '=', 'bulk_jobs.order_id')->select('bulk_jobs.*')
            ->with(self::relations());

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => BulkJobStatus::tryFrom($s) !== null));
            $query->whereIn('bulk_jobs.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->filled('filter.mode')) {
            $mode = (string) $request->input('filter.mode');
            $mode === 'unassigned' ? $query->whereNull('bulk_jobs.mode') : $query->where('bulk_jobs.mode', $mode);
        }
        if ($request->boolean('filter.open')) {
            $query->whereNotIn('bulk_jobs.status', [BulkJobStatus::Completed->value, BulkJobStatus::Cancelled->value]);
        }
        if ($request->boolean('filter.mine')) {
            $query->where('bulk_jobs.bulk_designer_id', $user->id);
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)
                ->orWhereIn('orders.customer_id', Customer::query()->select('id')->where('name', 'like', $like)));
        }

        $sort = (string) $request->input('sort', 'due_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        if (ltrim($sort, '-') === 'due_date') {
            $query->orderBy('orders.required_delivery_date', $direction)->orderByDesc('orders.is_urgent')->orderBy('bulk_jobs.id');
        } else {
            $query->orderBy('bulk_jobs.created_at', $direction)->orderBy('bulk_jobs.id', $direction);
        }

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), BulkJobResource::class);
    }

    public function show(BulkJob $job): BulkJobResource
    {
        Gate::authorize('view', $job);

        return new BulkJobResource(self::loadDetail($job));
    }

    /** The Job Card, assembled live from the authoritative records. */
    public function jobCard(BulkJob $job, JobCard $card): JsonResponse
    {
        Gate::authorize('view', $job);

        return ApiResponse::data($card->for($job));
    }

    /** The confirmed (locked) name/number list version the bulk job refers to, read-only. */
    public function garmentList(BulkJob $job, ConfirmedGarmentList $list): JsonResponse
    {
        Gate::authorize('view', $job);

        return ApiResponse::data($list->for($job));
    }

    public function exportGarmentList(BulkJob $job, GarmentCsv $csv): StreamedResponse
    {
        Gate::authorize('view', $job);
        $version = GarmentListVersion::query()->findOrFail($job->getAttribute('garment_list_version_id'));
        $item = $this->orderItem($job);
        $orderNo = (string) $job->order()->value('order_no');

        return response()->streamDownload(function () use ($csv, $version, $item): void {
            $csv->write($version, GarmentSchema::forProductType($item->product_type_id));
        }, "name-number-list-{$orderNo}-v{$version->version_no}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Internal Bulk Designers and active external producers for the assignment form. */
    public function assignees(Request $request, StaffDirectory $staff): JsonResponse
    {
        Gate::authorize('viewAny', BulkJob::class);
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can(Permissions::BULK_ASSIGN), 403);

        $order = null;
        if ($request->filled('job_id')) {
            $job = BulkJob::query()->find((int) $request->input('job_id'));
            $order = $job ? Order::query()->find($job->order_id) : null;
        }

        return ApiResponse::data([
            'designers' => $staff->bulkDesignersWithWorkload($order),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'service_type'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'service_type' => $s->service_type->value])->all(),
        ]);
    }

    public function assign(Request $request, BulkJob $job, AssignBulkJob $action): BulkJobResource
    {
        Gate::authorize('assign', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'mode' => ['required', Rule::enum(ProductionMode::class)],
            'bulk_designer_id' => ['sometimes', 'nullable', 'integer'],
            'supplier_id' => ['required_if:mode,external', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $mode = ProductionMode::from($data['mode']);
        $action->handle($actor, $job, (int) $data['version'], $mode,
            $mode === ProductionMode::Internal ? ($data['bulk_designer_id'] === null ? null : (int) $data['bulk_designer_id']) : null,
            $mode === ProductionMode::External ? ($data['supplier_id'] === null ? null : (int) $data['supplier_id']) : null,
            $data['notes'] ?? null, $data['reason'] ?? null);

        return new BulkJobResource(self::loadDetail($job->refresh()));
    }

    /** Signed short-lived URL AND the job policy; only files that belong to the job (BulkDocuments). */
    public function download(BulkJob $job, int $document, BulkDocuments $documents): StreamedResponse
    {
        Gate::authorize('view', $job);
        $file = $documents->find($job, $document);
        abort_if($file === null, 404);
        $inline = str_starts_with($file->mime_type, 'image/');

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'status', 'required_delivery_date', 'is_urgent', 'total_quantity',
                'total_minor', 'advance_required_minor', 'verified_paid_minor']),
            'order.customer:id,name', 'order.page:id,code,name', 'order.material:id,name',
            'branch:id,code,name', 'bulkDesigner:id,name', 'supplier:id,name',
            'designTask:id,order_item_id,approved_version_id,final_version_id',
            'designTask.orderItem:id,product_type_id', 'designTask.orderItem.productType:id,name',
            'designTask.approvedVersion:id,version_no', 'designTask.approvedVersion.files',
            'designTask.finalVersion:id,version_no,external_url',
            'garmentListVersion:id,version_no,total_quantity,status',
        ];
    }

    public static function loadDetail(BulkJob $job): BulkJob
    {
        return $job->load([
            ...self::relations(),
            'designTask.approvedVersion.files.document:id,original_name,mime_type',
            'designTask.finalVersion.files.document:id,original_name,mime_type',
            'assigner:id,name', 'approver:id,name', 'completer:id,name', 'approvedListVersion:id,version_no',
            'assignments.bulkDesigner:id,name', 'assignments.supplier:id,name', 'assignments.assigner:id,name',
            'measurements.enterer:id,name', 'measurements.updater:id,name',
            'measurementChanges.field:id,label', 'measurementChanges.changer:id,name',
            'checklist.template:id,name,version_no', 'checklist.items.completer:id,name', 'checklist.clientConfirmer:id,name',
            'versions.submitter:id,name', 'versions.garmentListVersion:id,version_no',
            'versions.files.document:id,original_name,mime_type,size_bytes', 'versions.reviews.reviewer:id,name',
            'documents.document:id,original_name', 'documents.uploader:id,name',
        ]);
    }

    private function orderItem(BulkJob $job): OrderItem
    {
        return OrderItem::query()->select(['id', 'order_id', 'product_type_id', 'quantity'])->where('order_id', $job->order_id)->orderBy('sort_order')->firstOrFail();
    }
}
