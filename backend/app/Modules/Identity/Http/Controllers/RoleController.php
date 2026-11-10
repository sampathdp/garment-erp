<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Actions\SaveRole;
use App\Modules\Identity\Http\Requests\StoreRoleRequest;
use App\Modules\Identity\Http\Resources\RoleResource;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\PermissionRegistrar;

final class RoleController
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        // Few rows by nature (configuration), so the list is not paginated.
        $roles = Role::query()->where('guard_name', 'web')->with('permissions:id,name')->withCount('users')->orderBy('name')->get();

        return new JsonResponse(['data' => RoleResource::collection($roles)->resolve()]);
    }

    public function show(Role $role): RoleResource
    {
        Gate::authorize('view', $role);

        return new RoleResource($role->load('permissions:id,name')->loadCount('users'));
    }

    /** The permission catalogue, grouped and labelled, for the role editor. */
    public function permissions(): JsonResponse
    {
        Gate::authorize('viewAny', Role::class);

        $groups = [];
        foreach (Permissions::groups() as $key => $group) {
            $groups[] = [
                'key' => $key,
                'label' => $group['label'],
                'permissions' => array_map(fn (string $name, string $label): array => ['name' => $name, 'label' => $label], array_keys($group['permissions']), $group['permissions']),
            ];
        }

        return new JsonResponse(['data' => $groups]);
    }

    public function store(StoreRoleRequest $request, SaveRole $action): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $role = $action->handle($actor, null, $request->attributesToSave(), $request->permissions() ?? []);

        return (new RoleResource($role->load('permissions:id,name')->loadCount('users')))->response()->setStatusCode(201);
    }

    public function update(StoreRoleRequest $request, Role $role, SaveRole $action): RoleResource
    {
        /** @var User $actor */
        $actor = $request->user();
        $role = $action->handle($actor, $role, $request->attributesToSave(), $request->permissions());

        return new RoleResource($role->load('permissions:id,name')->loadCount('users'));
    }

    public function destroy(Request $request, Role $role, AuditLogger $audit, PermissionRegistrar $registrar): Response
    {
        Gate::authorize('delete', $role->loadCount('users'));

        DB::transaction(function () use ($role, $audit, $request): void {
            $audit->record('roles.deleted', $role, ['before' => $role->only(['name', 'label']) + ['permissions' => $role->permissions()->pluck('name')->all()]], actor: $request->user());
            $role->delete();
        });
        $registrar->forgetCachedPermissions();

        return response()->noContent();
    }
}
