<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Resources;

use App\Modules\Organizations\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Branch */
final class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone_e164,
            'is_active' => $this->is_active,
            'members_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'abilities' => $this->when(! $request->routeIs('branches.index'), fn (): array => [
                'update' => Gate::allows('update', $this->resource),
            ]),
        ];
    }
}
