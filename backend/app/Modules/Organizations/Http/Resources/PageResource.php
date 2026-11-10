<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Models\Page;
use App\Support\Database\Pivot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Page */
final class PageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = ! $request->routeIs('pages.index');

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'platform' => $this->platform?->value,
            'external_url' => $this->external_url,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone_e164,
            'address' => $this->address,
            'pricing' => [
                'currency' => $this->currency,
                'default_advance_percent' => $this->default_advance_percent,
                'default_delivery_fee_minor' => $this->default_delivery_fee_minor,
            ],
            'invoice_prefix' => $this->invoice_prefix,
            'invoice_header_name' => $this->invoice_header_name,
            'invoice_address' => $this->when($detail, fn () => $this->invoice_address),
            'invoice_phone' => $this->when($detail, fn () => $this->invoice_phone_e164),
            'is_active' => $this->is_active,
            'owners' => $this->whenLoaded('users', fn () => $this->users
                ->filter(fn (User $u) => Pivot::flag($u, 'is_owner'))
                ->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->values()->all()),
            'members_count' => $this->whenCounted('users'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'abilities' => $this->when($detail, fn (): array => [
                'update' => Gate::allows('update', $this->resource),
                'manage_members' => Gate::allows('manageMembers', $this->resource),
            ]),
        ];
    }
}
