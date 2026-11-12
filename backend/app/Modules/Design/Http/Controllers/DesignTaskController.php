<?php

declare(strict_types=1);

namespace App\Modules\Design\Http\Controllers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Design\Actions\AssignDesigner;
use App\Modules\Design\Actions\ReviewDesign;
use App\Modules\Design\Actions\SubmitDesignVersion;
use App\Modules\Design\Actions\SubmitFinalDesign;
use App\Modules\Design\Enums\ApprovalChannel;
use App\Modules\Design\Enums\DesignTaskStatus;
use App\Modules\Design\Http\Resources\DesignTaskResource;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Design\Rules\DesignFile;
use App\Modules\Design\Rules\ExternalDesignLink;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use App\Modules\Identity\Support\StaffDirectory;
use App\Modules\Orders\Models\Order;
use App\Support\Exceptions\ApiException;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DesignTaskController
{
    /**
     * Design dashboard / centralised production plan. Default order: earliest due date first,
     * urgent before normal on the same day, then oldest first — so a new urgent job lands in its
     * priority position automatically.
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DesignTask::class);
        $request->validate([
            'filter.status' => ['sometimes', 'string', 'max:200'],
            'filter.designer_id' => ['sometimes', 'integer'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.open' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['due_date', '-due_date', 'created_at', '-created_at'])],
        ]);

        /** @var User $user */
        $user = $request->user();
        $query = DesignTask::query()->accessibleTo($user, Permissions::DESIGNS_VIEW, Permissions::DESIGNS_VIEW_ASSIGNED)
            ->join('orders', 'orders.id', '=', 'design_tasks.order_id')
            ->select('design_tasks.*')
            ->with(self::relations());

        if ($request->filled('filter.status')) {
            $statuses = array_values(array_filter(explode(',', (string) $request->input('filter.status')), fn ($s) => DesignTaskStatus::tryFrom($s) !== null));
            $query->whereIn('design_tasks.status', $statuses === [] ? ['-'] : $statuses);
        }
        if ($request->boolean('filter.open')) {
            $query->where('design_tasks.status', '!=', DesignTaskStatus::Completed->value);
        }
        if ($request->filled('filter.designer_id')) {
            $query->where('design_tasks.designer_id', (int) $request->input('filter.designer_id'));
        }
        if ($search = trim((string) $request->input('filter.search'))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('orders.order_no', 'like', $like)
                ->orWhereIn('orders.customer_id', Customer::query()->select('id')->where('name', 'like', $like)));
        }

        $sort = (string) $request->input('sort', 'due_date');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        if (ltrim($sort, '-') === 'due_date') {
            $query->orderBy('orders.required_delivery_date', $direction)->orderByDesc('orders.is_urgent')->orderBy('design_tasks.created_at');
        } else {
            $query->orderBy('design_tasks.created_at', $direction);
        }

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), DesignTaskResource::class);
    }

    public function show(DesignTask $task): DesignTaskResource
    {
        Gate::authorize('view', $task);

        return new DesignTaskResource($this->loadDetail($task));
    }

    public function assign(Request $request, DesignTask $task, AssignDesigner $action): DesignTaskResource
    {
        Gate::authorize('assignDesigner', $task);
        $data = $request->validate(['designer_id' => ['required', 'integer']]);
        /** @var User $actor */
        $actor = $request->user();

        return new DesignTaskResource($this->loadDetail($action->handle($actor, $task, (int) $data['designer_id'])));
    }

    /** Initial or revised design: preview image, PDF/CDR files and/or a shared link; always a new version. */
    public function submitVersion(Request $request, DesignTask $task, SubmitDesignVersion $action): DesignTaskResource
    {
        Gate::authorize('uploadVersion', $task);
        $data = $request->validate([
            'preview' => ['sometimes', 'file', new DesignFile(DesignFile::PREVIEW)],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['file', new DesignFile(DesignFile::SOURCE)],
            'external_url' => ['sometimes', 'nullable', 'string', new ExternalDesignLink],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        if (! $request->hasFile('preview') && ! $request->hasFile('files') && empty($data['external_url'])) {
            throw new ApiException(422, 'validation_failed', 'Add a preview, a PDF/CDR file or a Google Drive link.', ['files' => ['Add a preview, a PDF/CDR file or a Google Drive link.']]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $task, $request->file('preview'), array_values($request->file('files', [])), $data['external_url'] ?? null, $data['notes'] ?? null);

        return new DesignTaskResource($this->loadDetail($task->refresh()));
    }

    public function requestCorrection(Request $request, DesignTask $task, ReviewDesign $action): DesignTaskResource
    {
        Gate::authorize('requestCorrection', $task);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'channel' => ['required', Rule::enum(ApprovalChannel::class)],
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        return new DesignTaskResource($this->loadDetail($action->requestCorrection($actor, $task, $data['reason'], ApprovalChannel::from($data['channel']), $data['override_reason'] ?? null)));
    }

    public function approve(Request $request, DesignTask $task, ReviewDesign $action): DesignTaskResource
    {
        Gate::authorize('approve', $task);
        $data = $request->validate([
            'channel' => ['required', Rule::enum(ApprovalChannel::class)],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();

        return new DesignTaskResource($this->loadDetail($action->approve($actor, $task, ApprovalChannel::from($data['channel']), $data['comment'] ?? null)));
    }

    /** Final PDF/CDR file(s) and/or the approved final Google Drive link. */
    public function submitFinal(Request $request, DesignTask $task, SubmitFinalDesign $action): DesignTaskResource
    {
        Gate::authorize('submitFinal', $task);
        $data = $request->validate([
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => ['file', new DesignFile(DesignFile::SOURCE)],
            'external_url' => ['sometimes', 'nullable', 'string', new ExternalDesignLink],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $task, array_values($request->file('files', [])), $data['external_url'] ?? null, $data['notes'] ?? null);

        return new DesignTaskResource($this->loadDetail($task->refresh()));
    }

    /** Signed short-lived URL AND the task policy. Design files are always downloaded, never rendered inline. */
    public function download(DesignTask $task, DesignVersionFile $file): StreamedResponse
    {
        Gate::authorize('view', $task);
        $file->load('document', 'designVersion:id,design_task_id');
        abort_unless($file->designVersion->design_task_id === $task->id, 404);
        $document = $file->document;
        $inline = str_starts_with($document->mime_type, 'image/');

        return Storage::disk($document->disk)->response($document->path, $document->original_name, [
            'Content-Type' => $inline ? $document->mime_type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }

    /** Active users who can design (assignment dropdown), with workload and free status. */
    public function designers(Request $request, StaffDirectory $staff): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('viewAny', DesignTask::class);
        abort_unless($user->canAny([Permissions::DESIGNS_ASSIGN, Permissions::SETTINGS_MANAGE]), 403);

        $order = null;
        if ($request->filled('order_id')) {
            $order = Order::query()->find((int) $request->input('order_id'));
        } elseif ($request->filled('task_id')) {
            $task = DesignTask::query()->find((int) $request->input('task_id'));
            $order = $task ? Order::query()->find($task->order_id) : null;
        }

        return ApiResponse::data(['items' => $staff->designersWithWorkload($order)]);
    }

    /** @return array<int|string, mixed> */
    private static function relations(): array
    {
        return [
            'designer:id,name',
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'material_id', 'status', 'required_delivery_date', 'is_urgent', 'total_quantity',
                'material_details', 'design_instructions', 'custom_notes', 'overall_remarks', 'design_attachment_document_id']),
            'order.branch:id,code,name', 'order.page:id,code,name', 'order.customer:id,name', 'order.material:id,name',
            'orderItem:id,product_type_id', 'orderItem.productType:id,name',
        ];
    }

    private function loadDetail(DesignTask $task): DesignTask
    {
        return $task->load([
            ...self::relations(),
            'versions.creator:id,name', 'versions.files.document:id,original_name,mime_type,size_bytes',
            'versions.reviews.recorder:id,name', 'corrections.requester:id,name',
        ]);
    }
}
