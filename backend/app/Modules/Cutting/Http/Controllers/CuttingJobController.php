<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Http\Controllers;

use App\Modules\BulkProduction\Enums\IssueStatus;
use App\Modules\BulkProduction\Enums\IssueType;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Queries\ConfirmedGarmentList;
use App\Modules\BulkProduction\Queries\JobCard;
use App\Modules\Customers\Models\Customer;
use App\Modules\Cutting\Enums\CuttingJobStatus;
use App\Modules\Cutting\Http\Resources\CuttingJobResource;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Queries\CuttingJobFiles;
use App\Modules\Design\Enums\DesignFileKind;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cutting dashboard and read endpoints (Phase 07).
 *
 * Operation Admins (cutting.view) see every cutting job in scope; Cutting Staff
 * (cutting.view_assigned) see only the jobs assigned to them. Default order: expected completion
 * / due date — earliest due date first, urgent first on the same day, then oldest.
 */
final class CuttingJobController
{
    private const SORTS = ['due_date', '-due_date', 'expected_completion_date', '-expected_completion_date', 'created_at', '-created_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CuttingJob::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:300'],
            'filter.open' => ['sometimes', 'boolean'],
            'filter.mine' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        $query = CuttingJob::query()->accessibleTo($user, Permissions::CUTTING_VIEW, Permissions::CUTTING_VIEW_ASSIGNED, 'supervisor_id')
            ->join('orders', 'orders.id', '=', 'cutting_jobs.order_id')
            ->select('cutting_jobs.*')
            ->with(self::relations())
            ->withCount(['issues as open_issue_count' => fn ($q) => $q->whereIn('status', IssueStatus::unresolvedValues())])
            ->withSum(['issues as defective_quantity' => fn ($q) => $q->whereIn('status', IssueStatus::unresolvedValues())->whereIn('type', self::pieceDefects())], 'quantity_affected');

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => CuttingJobStatus::tryFrom($s) !== null));
            $query->whereIn('cutting_jobs.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->boolean('filter.open')) {
            $query->where('cutting_jobs.status', '!=', CuttingJobStatus::Completed->value);
        }
        if ($request->boolean('filter.mine')) {
            $query->where('cutting_jobs.supervisor_id', $user->id);
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)
                ->orWhereIn('orders.customer_id', Customer::query()->select('id')->where('name', 'like', $like)));
        }

        $sort = (string) $request->input('sort', 'due_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        match (ltrim($sort, '-')) {
            'due_date' => $query->orderBy('orders.required_delivery_date', $direction)->orderByDesc('orders.is_urgent')->orderBy('cutting_jobs.id'),
            'expected_completion_date' => $query->orderBy('cutting_jobs.expected_completion_date', $direction)->orderBy('cutting_jobs.id'),
            default => $query->orderBy('cutting_jobs.created_at', $direction)->orderBy('cutting_jobs.id', $direction),
        };

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), CuttingJobResource::class);
    }

    public function show(CuttingJob $job): CuttingJobResource
    {
        Gate::authorize('view', $job);

        return new CuttingJobResource(self::loadDetail($job));
    }

    /** The Job Card (read model of the bulk job card) with the design image served by the cutting route. */
    public function jobCard(CuttingJob $job, JobCard $card): JsonResponse
    {
        Gate::authorize('view', $job);
        $print = PrintJob::query()->findOrFail($job->print_job_id);
        $data = $card->for(BulkJob::query()->findOrFail($print->getAttribute('bulk_job_id')));
        $preview = DesignVersionFile::query()->where('design_version_id', $print->getAttribute('design_version_id'))
            ->where('kind', DesignFileKind::Preview->value)->orderBy('id')->value('document_id');
        $data['design_image_url'] = $preview === null ? null : self::fileUrl($job, (int) $preview);
        $data['cutting'] = ['id' => $job->id, 'status' => ['value' => $job->status->value, 'label' => $job->status->label()]];

        return ApiResponse::data($data);
    }

    /** The confirmed name/number list version the job was printed from (read-only). */
    public function nameList(CuttingJob $job, ConfirmedGarmentList $list): JsonResponse
    {
        Gate::authorize('view', $job);
        $print = PrintJob::query()->findOrFail($job->print_job_id);

        return ApiResponse::data($list->for(BulkJob::query()->findOrFail($print->getAttribute('bulk_job_id')), (int) $print->getAttribute('garment_list_version_id')));
    }

    /** Active users who can be assigned as the cutter (or named responsible for a checklist task). */
    public function cutters(CuttingJob $job): JsonResponse
    {
        Gate::authorize('view', $job);
        $order = $job->order()->firstOrFail(['id', 'branch_id', 'page_id']);
        $users = User::query()->where('is_active', true)->permission([Permissions::CUTTING_UPDATE_CHECKLIST, Permissions::CUTTING_MANAGE])->orderBy('name')->get()
            ->filter(fn (User $u) => $u->canAccessBranch($order->branch_id) && $u->canAccessPage($order->page_id))
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all();

        return new JsonResponse(['data' => $users]);
    }

    /** Signed short-lived URL AND the cutting policy; only files that belong to the job (CuttingJobFiles). */
    public function download(CuttingJob $job, int $document, CuttingJobFiles $files): StreamedResponse
    {
        Gate::authorize('view', $job);
        $file = $files->find($job, $document);
        abort_if($file === null, 404);
        $inline = in_array($file->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true);

        return Storage::disk($file->disk)->response($file->path, $file->original_name, [
            'Content-Type' => $inline ? $file->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    public static function fileUrl(CuttingJob $job, int $documentId): string
    {
        return URL::temporarySignedRoute('cutting-jobs.files.download', now()->addMinutes((int) config('documents.download_url_ttl_minutes', 5)), ['job' => $job->id, 'document' => $documentId]);
    }

    /** @return list<string> */
    public static function pieceDefects(): array
    {
        return array_values(array_map(fn (IssueType $t) => $t->value, array_filter(IssueType::cases(), fn (IssueType $t) => $t->isPieceDefect())));
    }

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'status', 'required_delivery_date', 'is_urgent', 'total_quantity']),
            'order.customer:id,name', 'order.page:id,code,name', 'order.material:id,name', 'branch:id,code,name',
            'orderItem:id,product_type_id', 'orderItem.productType:id,name', 'supervisor:id,name',
            'quantities' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
            'printJob' => fn ($q) => $q->select(['id', 'order_id', 'design_version_id', 'garment_list_version_id', 'supplier_id', 'recipient_name', 'mode', 'printed_quantity', 'printing_completed_at']),
            'printJob.supplier:id,name', 'printJob.designVersion:id,version_no', 'printJob.designVersion.files',
            'printJob.garmentListVersion:id,version_no,total_quantity,status',
            'printJob.gatePasses' => fn ($q) => $q->select(['id', 'print_job_id', 'gate_pass_no', 'gate_pass_date'])->orderBy('id'),
        ];
    }

    public static function loadDetail(CuttingJob $job): CuttingJob
    {
        return $job->load([
            ...self::relations(),
            'quantities.updater:id,name', 'assigner:id,name', 'completer:id,name',
            'checklistRuns' => fn ($q) => $q->whereNull('superseded_at')->with(['clientConfirmer:id,name',
                'items' => fn ($i) => $i->orderBy('sort_order')->orderBy('id')->with(['responsible:id,name', 'completer:id,name'])]),
            'assignments' => fn ($q) => $q->orderByDesc('id')->with(['cutter:id,name', 'assigner:id,name']),
            'events' => fn ($q) => $q->orderByDesc('id')->with('actor:id,name'),
            'issues' => fn ($q) => $q->orderByDesc('id')->with(['raiser:id,name', 'assignee:id,name']),
        ])->loadCount(['issues as open_issue_count' => fn ($q) => $q->whereIn('status', IssueStatus::unresolvedValues())])
            ->loadSum(['issues as defective_quantity' => fn ($q) => $q->whereIn('status', IssueStatus::unresolvedValues())->whereIn('type', self::pieceDefects())], 'quantity_affected');
    }
}
