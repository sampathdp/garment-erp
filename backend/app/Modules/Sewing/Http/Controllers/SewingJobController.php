<?php

declare(strict_types=1);

namespace App\Modules\Sewing\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Sewing\Actions\RunSewing;
use App\Modules\Sewing\Enums\SewingJobStatus;
use App\Modules\Sewing\Http\Resources\SewingJobResource;
use App\Modules\Sewing\Models\SewingJob;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Minimal sewing step (Phase 08): list, detail, start, complete. */
final class SewingJobController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', SewingJob::class);
        $request->validate(['filter.status' => ['sometimes', Rule::enum(SewingJobStatus::class)], 'filter.open' => ['sometimes', 'boolean']]);
        /** @var User $user */
        $user = $request->user();

        $query = SewingJob::query()->visibleTo($user)
            ->join('orders', 'orders.id', '=', 'sewing_jobs.order_id')->select('sewing_jobs.*')
            ->with(self::relations())
            ->when($request->filled('filter.status'), fn ($q) => $q->where('sewing_jobs.status', (string) $request->input('filter.status')))
            ->when($request->boolean('filter.open'), fn ($q) => $q->where('sewing_jobs.status', '!=', SewingJobStatus::Completed->value))
            ->orderBy('orders.required_delivery_date')->orderByDesc('orders.is_urgent')->orderBy('sewing_jobs.id');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), SewingJobResource::class);
    }

    public function show(SewingJob $job): SewingJobResource
    {
        Gate::authorize('view', $job);

        return new SewingJobResource($job->load(self::relations()));
    }

    public function start(Request $request, SewingJob $job, RunSewing $action): SewingJobResource
    {
        Gate::authorize('start', $job);
        $data = $request->validate(['version' => ['required', 'integer']]);
        $action->start($this->actor($request), $job, (int) $data['version']);

        return new SewingJobResource($job->refresh()->load(self::relations()));
    }

    public function complete(Request $request, SewingJob $job, RunSewing $action): SewingJobResource
    {
        Gate::authorize('complete', $job);
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'sewn_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';
        $action->complete($this->actor($request), $job, (int) $data['version'], (int) $data['sewn_quantity'], $notes === '' ? null : $notes);

        return new SewingJobResource($job->refresh()->load(self::relations()));
    }

    /** @return array<int|string, mixed> */
    private static function relations(): array
    {
        return [
            'order' => fn ($q) => $q->select(['id', 'order_no', 'branch_id', 'page_id', 'customer_id', 'status', 'required_delivery_date', 'is_urgent']),
            'order.customer:id,name', 'order.page:id,code', 'branch:id,code', 'orderItem:id,product_type_id', 'orderItem.productType:id,name',
            'starter:id,name', 'completer:id,name',
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
