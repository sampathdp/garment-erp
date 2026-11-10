<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\SaveOrganizationUnit;
use App\Modules\Organizations\Http\Requests\StorePageRequest;
use App\Modules\Organizations\Http\Resources\PageResource;
use App\Modules\Organizations\Models\Page;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PageController
{
    private const SORTS = ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Page::class);

        $request->validate([
            'filter.is_active' => ['sometimes', 'boolean'],
            'filter.search' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Page::query()
            ->visibleTo($request->user())
            ->select(['id', 'code', 'name', 'platform', 'external_url', 'contact_email', 'contact_phone_e164', 'address',
                'currency', 'default_advance_percent', 'default_delivery_fee_minor', 'invoice_prefix', 'invoice_header_name', 'is_active', 'created_at'])
            ->with(['users' => fn ($q) => $q->select(['users.id', 'users.name'])->wherePivot('is_owner', true)])
            ->withCount('users');

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        if ($search = $request->input('filter.search')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes((string) $search, '%_\\').'%')
                ->orWhere('code', 'like', addcslashes((string) $search, '%_\\').'%'));
        }

        ListQuery::applySort($query, $request, self::SORTS, 'name');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), PageResource::class);
    }

    public function show(Page $page): PageResource
    {
        Gate::authorize('view', $page);

        return new PageResource($this->withOwners($page));
    }

    public function store(StorePageRequest $request, SaveOrganizationUnit $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $page = $action->handle($actor, Page::class, null, $request->attributesToSave());

        return (new PageResource($this->withOwners($page)))->response()->setStatusCode(201);
    }

    public function update(StorePageRequest $request, Page $page, SaveOrganizationUnit $action): PageResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new PageResource($this->withOwners($action->handle($actor, Page::class, $page, $request->attributesToSave())));
    }

    private function withOwners(Page $page): Page
    {
        return $page->load(['users' => fn ($q) => $q->select(['users.id', 'users.name'])->wherePivot('is_owner', true)])->loadCount('users');
    }
}
