<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Actions\ChangeUserStatus;
use App\Modules\Identity\Actions\CreateUser;
use App\Modules\Identity\Actions\SendPasswordResetLink;
use App\Modules\Identity\Actions\SetUserPassword;
use App\Modules\Identity\Actions\SyncUserAssignments;
use App\Modules\Identity\Actions\SyncUserRoles;
use App\Modules\Identity\Actions\UpdateUser;
use App\Modules\Identity\Http\Requests\ChangeUserStatusRequest;
use App\Modules\Identity\Http\Requests\SendPasswordResetRequest;
use App\Modules\Identity\Http\Requests\SetUserPasswordRequest;
use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\SyncUserAssignmentsRequest;
use App\Modules\Identity\Http\Requests\SyncUserRolesRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class UserController
{
    private const SORTS = ['name' => 'name', 'email' => 'email', 'created_at' => 'created_at', 'last_login_at' => 'last_login_at'];

    private const RELATIONS = ['roles:id,name,label', 'branches:branches.id,code,name', 'pages:pages.id,code,name'];

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $request->validate([
            'filter.search' => ['sometimes', 'string', 'max:100'],
            'filter.is_active' => ['sometimes', 'boolean'],
            'filter.role' => ['sometimes', 'string', 'max:100'],
            'filter.branch_id' => ['sometimes', 'integer'],
            'filter.page_id' => ['sometimes', 'integer'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $query = User::query()->visibleTo($actor)
            ->select(['id', 'name', 'email', 'phone_e164', 'is_active', 'last_login_at', 'deactivated_at', 'created_at'])
            ->with(self::RELATIONS);

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }
        if (is_string($search = $request->input('filter.search')) && $search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        if (is_string($role = $request->input('filter.role')) && $role !== '') {
            $query->whereHas('roles', fn (Builder $q) => $q->where('name', $role));
        }
        if ($request->filled('filter.branch_id')) {
            $query->whereHas('branches', fn (Builder $q) => $q->where('branches.id', (int) $request->input('filter.branch_id')));
        }
        if ($request->filled('filter.page_id')) {
            $query->whereHas('pages', fn (Builder $q) => $q->where('pages.id', (int) $request->input('filter.page_id')));
        }

        ListQuery::applySort($query, $request, self::SORTS, 'name');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), UserResource::class);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return new UserResource($user->load(self::RELATIONS));
    }

    public function store(StoreUserRequest $request, CreateUser $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        /** @var array{name: string, email: string, phone_e164?: string|null} $details */
        $details = $request->details();

        $user = $action->handle(
            $actor,
            $details,
            (string) $request->validated('password'),
            array_values(array_map('strval', (array) $request->validated('roles', []))),
            $request->ids('branch_ids'),
            $request->ids('page_ids'),
            $request->ids('owner_page_ids'),
        );

        return (new UserResource($user->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(StoreUserRequest $request, User $user, UpdateUser $action): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($action->handle($actor, $user, $request->details())->load(self::RELATIONS));
    }

    public function status(ChangeUserStatusRequest $request, User $user, ChangeUserStatus $action): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $reason = $request->validated('reason');

        $action->handle($actor, $user, $request->boolean('is_active'), is_string($reason) ? $reason : null);

        return new UserResource($user->load(self::RELATIONS));
    }

    public function roles(SyncUserRolesRequest $request, User $user, SyncUserRoles $action): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $user, $request->roles());

        return new UserResource($user->load(self::RELATIONS));
    }

    public function assignments(SyncUserAssignmentsRequest $request, User $user, SyncUserAssignments $action): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $user, $request->ids('branch_ids'), $request->ids('page_ids'), $request->ids('owner_page_ids'));

        return new UserResource($user->load(self::RELATIONS));
    }

    public function sendPasswordReset(SendPasswordResetRequest $request, User $user, SendPasswordResetLink $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->byAdministrator($actor, $user, $request->boolean('sign_out', true), invitation: $user->last_login_at === null);

        return response()->noContent();
    }

    /** Administrator sets a new password directly (no email); sessions are ended unless sign_out=false. */
    public function setPassword(SetUserPasswordRequest $request, User $user, SetUserPassword $action): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $user, (string) $request->validated('password'), $request->boolean('sign_out', true));

        return response()->noContent();
    }

    public function activity(Request $request, User $user): JsonResponse
    {
        Gate::authorize('viewActivity', $user);

        $request->validate(['filter.action' => ['sometimes', 'string', 'max:100']]);

        // Actions performed by the user, plus actions performed on the user's account.
        $query = AuditLog::query()
            ->with('actor:id,name')
            ->where(fn (Builder $q) => $q->where('actor_id', $user->id)
                ->orWhere(fn (Builder $s) => $s->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)));

        if (is_string($action = $request->input('filter.action'))) {
            $query->where('action', 'like', addcslashes($action, '%_\\').'%');
        }

        $query->orderByDesc('occurred_at')->orderByDesc('id');

        return ApiResponse::paginated($query->paginate(ListQuery::perPage($request))->withQueryString(), AuditLogResource::class);
    }
}
