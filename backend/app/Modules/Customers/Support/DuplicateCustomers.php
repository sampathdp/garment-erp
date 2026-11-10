<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Duplicate detection within one page. Records are never merged:
 *  - same phone (as phone or WhatsApp) AND same name → the same customer (reused / 409);
 *  - same phone, different name → possible duplicate: staff pick the existing customer or
 *    confirm a different person (families, schools and clubs share numbers).
 */
final class DuplicateCustomers
{
    /** @return Collection<int, Customer> */
    public function sharingPhone(?int $pageId, string $phoneE164, ?int $ignoreId = null): Collection
    {
        return Customer::query()
            ->select(['id', 'customer_code', 'name', 'phone_e164', 'whatsapp_e164', 'page_id'])
            ->when($pageId === null, fn (Builder $q) => $q->whereNull('page_id'), fn (Builder $q) => $q->where('page_id', $pageId))
            ->where(fn (Builder $q) => $q->where('phone_e164', $phoneE164)->orWhere('whatsapp_e164', $phoneE164))
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    /** @param  Collection<int, Customer>  $candidates */
    public function exactMatch(Collection $candidates, string $name): ?Customer
    {
        $normalized = CustomerRules::normalizeName($name);

        return $candidates->first(fn (Customer $c): bool => CustomerRules::normalizeName($c->name) === $normalized);
    }
}
