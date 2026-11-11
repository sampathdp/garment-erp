<?php

declare(strict_types=1);

namespace App\Modules\Printing\Http\Controllers;

use App\Modules\BulkProduction\Enums\BulkJobStatus;
use App\Modules\BulkProduction\Enums\ProductionMode;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Printing\Actions\GeneratePrintDocument;
use App\Modules\Printing\Enums\PrintDocument;
use App\Modules\Printing\Enums\PrintJobStatus;
use App\Modules\Printing\Http\Resources\PrintJobResource;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Queries\PrintJobCard;
use App\Modules\Printing\Queries\PrintJobFiles;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printing dashboard and read endpoints (Phase 06).
 *
 * Only printing jobs exist on the dashboard, and a printing job is opened only when its bulk
 * job is completed; the list additionally joins on a completed bulk job, so an incomplete bulk
 * job can never be shown as printing-ready. Default order: earliest due date, urgent first on
 * the same day, then oldest.
 */
final class PrintJobController
{
    private const SORTS = ['due_date', '-due_date', 'created_at', '-created_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PrintJob::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:300'],
            'filter.mode' => ['sometimes', Rule::enum(ProductionMode::class)],
            'filter.open' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        $query = PrintJob::query()->visibleTo($user)
            ->join('orders', 'orders.id', '=', 'print_jobs.order_id')
            ->join('bulk_jobs', 'bulk_jobs.id', '=', 'print_jobs.bulk_job_id')
            ->where('bulk_jobs.status', BulkJobStatus::Completed->value)
            ->select('print_jobs.*')
            ->with(self::relations());

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => PrintJobStatus::tryFrom($s) !== null));
            $query->whereIn('print_jobs.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->filled('filter.mode')) {
            $query->where('print_jobs.mode', (string) $request->input('filter.mode'));
        }
        if ($request->boolean('filter.open')) {
            $query->whereNotIn('print_jobs.status', [PrintJobStatus::PrintingCompleted->value, PrintJobStatus::Invalidated->value]);
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)
                ->orWhereIn('orders.customer_id', Customer::query()->select('id')->where('name', 'like', $like)));
        }

        $sort = (string) $request->input('sort', 'due_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        if (ltrim($sort, '-') === 'due_date') {
            $query->orderBy('orders.required_delivery_date', $direction)->orderByDesc('orders.is_urgent')->orderBy('print_jobs.id');
        } else {
            $query->orderBy('print_jobs.created_at', $direction)->orderBy('print_jobs.id', $direction);
        }

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), PrintJobResource::class);
    }

    public function show(PrintJob $job): PrintJobResource
    {
        Gate::authorize('view', $job);

        return new PrintJobResource(self::loadDetail($job));
    }

    /** The Job Card for on-screen review. Printing it is a separate, server-gated endpoint (print()). */
    public function jobCard(PrintJob $job, PrintJobCard $documents): JsonResponse
    {
        Gate::authorize('view', $job);

        return ApiResponse::data($documents->jobCard($job));
    }

    /** The confirmed name/number list for on-screen review (read-only, not printable here). */
    public function nameList(PrintJob $job, PrintJobCard $documents): JsonResponse
    {
        Gate::authorize('view', $job);

        return ApiResponse::data($documents->nameList($job));
    }

    /**
     * Server-generated printable document. Refused (409 document_printing_locked) until the
     * printing sample is approved, whatever the client shows.
     */
    public function print(Request $request, PrintJob $job, string $document, GeneratePrintDocument $action): Response
    {
        $type = PrintDocument::tryFrom($document);
        abort_if($type === null, 404);
        Gate::authorize('printDocuments', $job);
        /** @var User $actor */
        $actor = $request->user();
        $html = $action->handle($actor, $job, $type);

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "inline; filename=\"{$type->value}-{$job->id}.html\"",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
        ]);
    }

    /** Active suppliers that can be chosen as the printing company. */
    public function recipients(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PrintJob::class);

        return ApiResponse::data([
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'service_type'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'service_type' => $s->service_type->value])->all(),
        ]);
    }

    /** Signed short-lived URL AND the printing policy; only files that belong to the job (PrintJobFiles). */
    public function download(PrintJob $job, int $document, PrintJobFiles $files): StreamedResponse
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

    /** @return array<int|string, mixed> */
    public static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'status', 'required_delivery_date', 'is_urgent', 'total_quantity']),
            'order.customer:id,name', 'order.page:id,code,name', 'order.material:id,name', 'branch:id,code,name',
            'orderItem:id,product_type_id', 'orderItem.productType:id,name', 'supplier:id,name',
            'designVersion:id,version_no', 'designVersion.files',
            'bulkVersion:id,version_no,transfer_url', 'bulkVersion.files.document:id,original_name,mime_type,size_bytes',
            'bulkJob' => fn ($q) => $q->select(['id', 'order_id', 'branch_id', 'mode', 'status', 'bulk_designer_id', 'supplier_id', 'completed_at']),
            'bulkJob.supplier:id,name', 'bulkJob.bulkDesigner:id,name',
            'bulkJob.measurements' => fn ($q) => $q->select(['id', 'bulk_job_id', 'bulk_measurement_field_id', 'value']),
            'bulkJob.measurements.field:id,key,label,unit,decimals,sort_order',
            'garmentListVersion:id,version_no,total_quantity,status',
            'samples' => fn ($q) => $q->select(['id', 'print_job_id', 'sample_no', 'decision']),
        ];
    }

    public static function loadDetail(PrintJob $job): PrintJob
    {
        return $job->load([
            ...self::relations(),
            'samples' => fn ($q) => $q->with(['documents.document:id,original_name,mime_type,size_bytes', 'uploader:id,name', 'recorder:id,name']),
            'events.actor:id,name', 'gatePasses.document:id,original_name', 'gatePasses.recorder:id,name',
            'sender:id,name', 'printingStarter:id,name', 'printingCompleter:id,name',
        ]);
    }
}
