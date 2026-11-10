<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\SyncPageMembers;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Page;
use App\Support\Database\Pivot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Users associated with a page, and its Page Owners. */
final class PageMemberController
{
    public function index(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('view', $page);
        Gate::authorize('viewAny', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $members = $page->users()->visibleTo($actor)
            ->select(['users.id', 'users.name', 'users.email', 'users.is_active'])
            ->with('roles:id,name,label')
            ->orderBy('users.name')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $members->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'is_active' => $u->is_active,
            'is_owner' => Pivot::flag($u, 'is_owner'),
            'roles' => $u->roles->map(fn ($r): array => Role::ref($r))->values()->all(),
        ])->all()]);
    }

    public function update(Request $request, Page $page, SyncPageMembers $action): JsonResponse
    {
        Gate::authorize('manageMembers', $page);

        $data = $request->validate([
            'members' => ['present', 'array', 'max:500'],
            'members.*.user_id' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'members.*.is_owner' => ['required', 'boolean'],
        ]);

        $members = [];
        foreach ($data['members'] as $member) {
            $members[(int) $member['user_id']] = (bool) $member['is_owner'];
        }

        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $page, $members);

        return $this->index($request, $page);
    }
}
