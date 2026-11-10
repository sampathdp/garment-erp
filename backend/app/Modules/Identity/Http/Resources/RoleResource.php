<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Expects `permissions` loaded and `users_count` counted.
 *
 * @mixin Role
 */
final class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->displayName(),
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_locked' => $role->isLocked(),
            'users_count' => (int) ($role->getAttributes()['users_count'] ?? 0),
            'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            'abilities' => [
                'update' => Gate::allows('update', $role),
                'delete' => Gate::allows('delete', $role),
            ],
        ];
    }
}
