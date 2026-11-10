<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Support\Database\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user with roles, permissions and data scope, for authorization-aware UI.
 * The frontend uses this for navigation only; the API re-checks everything.
 *
 * @mixin User
 */
final class CurrentUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $user->loadMissing('roles');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone_e164,
            'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
            'password_changed_at' => $user->password_changed_at?->toIso8601ZuluString(),
            'roles' => $user->roles->sortBy('name')->map(fn ($r): array => Role::ref($r))->values()->all(),
            'permissions' => collect($user->permissionNames())->sort()->values()->all(),
            'scope' => [
                'all_branches' => $user->hasAllBranchAccess(),
                'all_pages' => $user->hasAllPageAccess(),
                'branches' => $user->branches()->orderBy('name')->get(['branches.id', 'code', 'name'])
                    ->map(fn ($b): array => ['id' => $b->id, 'code' => $b->code, 'name' => $b->name])->all(),
                'pages' => $user->pages()->orderBy('name')->get(['pages.id', 'code', 'name'])
                    ->map(fn ($p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'is_owner' => Pivot::flag($p, 'is_owner')])->all(),
            ],
        ];
    }
}
