<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Support\Database\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * User for administration screens. Expects `roles`, `branches` and `pages` to be eager loaded.
 * `abilities` drive the UI only; every action is re-authorized by the API.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone_e164,
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'deactivated_at' => $user->deactivated_at?->toIso8601ZuluString(),
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'roles' => $user->roles->sortBy('name')->map(fn ($r): array => Role::ref($r))->values()->all(),
            'branches' => $user->branches->sortBy('name')->map(fn (Branch $b): array => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->values()->all(),
            'pages' => $user->pages->sortBy('name')->map(fn (Page $p): array => [
                'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_owner' => Pivot::flag($p, 'is_owner'),
            ])->values()->all(),
            'abilities' => $this->when($request->routeIs('users.show', 'users.update', 'users.store', 'users.status', 'users.roles', 'users.assignments'), fn (): array => [
                'update' => Gate::allows('update', $user),
                'administer' => Gate::allows('administer', $user),
                'view_activity' => Gate::allows('viewActivity', $user),
            ]),
        ];
    }
}
