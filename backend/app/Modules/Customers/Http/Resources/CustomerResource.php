<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/** @mixin Customer */
final class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $detail = $request->routeIs('customers.show', 'customers.store', 'customers.update');

        return [
            'id' => $this->id,
            'customer_code' => $this->customer_code,
            'name' => $this->name,
            'phone' => $this->phone_e164,
            'whatsapp' => $this->whatsapp_e164,
            'other_phone' => $this->alt_phone_e164,
            'address' => $this->address_line1,
            'email' => $this->when($detail, fn () => $this->email),
            'notes' => $this->when($detail, fn () => $this->notes),
            'page' => $this->whenLoaded('page', fn () => $this->page ? ['id' => $this->page->id, 'code' => $this->page->code, 'name' => $this->page->name] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? ['id' => $this->branch->id, 'code' => $this->branch->code, 'name' => $this->branch->name] : null),
            'orders_count' => $this->whenCounted('orders'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'abilities' => $this->when($detail, fn (): array => [
                'update' => Gate::allows('update', $this->resource),
                'delete' => Gate::allows('delete', $this->resource),
            ]),
        ];
    }
}
