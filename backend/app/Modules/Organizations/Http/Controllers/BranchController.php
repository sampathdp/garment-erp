<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\SaveOrganizationUnit;
use App\Modules\Organizations\Http\Requests\StoreBranchRequest;
use App\Modules\Organizations\Http\Resources\BranchResource;
use App\Modules\Organizations\Models\Branch;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Reference implementation of the list/detail pattern:
 * authorize -> scope with visibleTo() -> whitelisted sort -> paginate -> resource.
 */
final class BranchController
{
    private const SORTS = ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Branch::class);

        $request->validate([
            'filter.is_active' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Branch::query()
            ->visibleTo($request->user())
            ->select(['id', 'code', 'name', 'address', 'phone_e164', 'is_active', 'created_at'])
            ->withCount('users');

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        if ($search = $request->input('filter.search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%')
                ->orWhere('code', 'like', addcslashes((string) $search, '%_\\').'%'));
        }

        ListQuery::applySort($query, $request, self::SORTS, 'name');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), BranchResource::class);
    }

    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('view', $branch);

        return new BranchResource($branch->loadCount('users'));
    }

    public function store(StoreBranchRequest $request, SaveOrganizationUnit $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $branch = $action->handle($actor, Branch::class, null, $request->attributesToSave());

        return (new BranchResource($branch->loadCount('users')))->response()->setStatusCode(201);
    }

    public function update(StoreBranchRequest $request, Branch $branch, SaveOrganizationUnit $action): BranchResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new BranchResource($action->handle($actor, Branch::class, $branch, $request->attributesToSave())->loadCount('users'));
    }
}
