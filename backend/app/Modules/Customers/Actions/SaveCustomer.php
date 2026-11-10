<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\DuplicateCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Organizations\Actions\NextSequenceValue;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a customer with page-scoped duplicate protection (see DuplicateCustomers).
 * `resolve()` serves the order form: it reuses an exact match instead of failing.
 */
final class SaveCustomer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DuplicateCustomers $duplicates,
        private readonly NextSequenceValue $sequence,
    ) {}

    /** @param  array<string, mixed>  $attributes  column => value (CustomerRules::toAttributes) */
    public function create(User $actor, int $pageId, ?int $branchId, array $attributes, bool $confirmDistinct): Customer
    {
        return DB::transaction(function () use ($actor, $pageId, $branchId, $attributes, $confirmDistinct): Customer {
            $candidates = $this->duplicates->sharingPhone($pageId, (string) $attributes['phone_e164']);
            $same = $this->duplicates->exactMatch($candidates, (string) $attributes['name']);
            if ($same !== null) {
                throw new ApiException(409, 'duplicate_customer', "This customer already exists as {$same->customer_code}.",
                    ['phone' => ["{$same->name} ({$same->customer_code}) already has this phone number."]]);
            }
            $this->guardPossibleDuplicate($candidates->all(), $confirmDistinct, 'phone');

            return $this->insert($actor, $pageId, $branchId, $attributes, $candidates->isNotEmpty());
        });
    }

    /**
     * Order form (runs inside the order transaction): reuse the customer when name and phone match
     * exactly, otherwise create one. Only EMPTY fields of a reused customer are filled; existing
     * details are never overwritten from the order form.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function resolve(User $actor, int $pageId, int $branchId, array $attributes, bool $confirmDistinct): Customer
    {
        $candidates = $this->duplicates->sharingPhone($pageId, (string) $attributes['phone_e164']);
        $same = $this->duplicates->exactMatch($candidates, (string) $attributes['name']);

        if ($same !== null) {
            $customer = Customer::query()->lockForUpdate()->findOrFail($same->id);
            $missing = array_filter($attributes, fn ($value, string $column): bool => $value !== null && $customer->getAttribute($column) === null, ARRAY_FILTER_USE_BOTH);
            if ($missing !== []) {
                $customer->fill($missing)->save();
                $this->audit->record('customers.updated', $customer, ['before' => [], 'after' => $missing], actor: $actor);
            }

            return $customer;
        }

        $this->guardPossibleDuplicate($candidates->all(), $confirmDistinct, 'customer.phone');

        return $this->insert($actor, $pageId, $branchId, $attributes, $candidates->isNotEmpty());
    }

    /** @param  array<string, mixed>  $attributes */
    public function update(User $actor, Customer $customer, array $attributes, bool $confirmDistinct): Customer
    {
        return DB::transaction(function () use ($actor, $customer, $attributes, $confirmDistinct): Customer {
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $before = $customer->only(array_keys($attributes));
            $customer->fill($attributes);
            $dirty = $customer->getDirty();
            if ($dirty === []) {
                return $customer;
            }

            if (array_key_exists('phone_e164', $dirty) || array_key_exists('name', $dirty)) {
                $candidates = $this->duplicates->sharingPhone($customer->page_id, (string) $customer->phone_e164, $customer->id);
                $same = $this->duplicates->exactMatch($candidates, (string) $customer->name);
                if ($same !== null) {
                    throw new ApiException(409, 'duplicate_customer', "This would duplicate {$same->customer_code}.",
                        ['phone' => ["{$same->name} ({$same->customer_code}) already has this phone number."]]);
                }
                $this->guardPossibleDuplicate($candidates->all(), $confirmDistinct, 'phone');
            }

            $customer->save();
            $this->audit->record('customers.updated', $customer, ['before' => array_intersect_key($before, $dirty), 'after' => $dirty], actor: $actor);

            return $customer;
        });
    }

    /** @param  list<Customer>  $candidates */
    private function guardPossibleDuplicate(array $candidates, bool $confirmDistinct, string $field): void
    {
        if ($candidates === [] || $confirmDistinct) {
            return;
        }

        $names = implode(', ', array_map(fn (Customer $c): string => "{$c->name} ({$c->customer_code})", $candidates));

        throw new ApiException(422, 'possible_duplicate_customer',
            'A customer with this phone number already exists. Select the existing customer, or confirm this is a different person.',
            [$field => ["Already used by: {$names}."]]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function insert(User $actor, int $pageId, ?int $branchId, array $attributes, bool $sharedPhone): Customer
    {
        $number = $this->sequence->handle('customer', 0, 0);
        $customer = Customer::query()->create([
            ...$attributes,
            'customer_code' => 'CUS-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            'page_id' => $pageId,
            'branch_id' => $branchId,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('customers.created', $customer, ['after' => [
            'customer_code' => $customer->customer_code, 'page_id' => $pageId, 'branch_id' => $branchId,
            'confirmed_distinct_despite_shared_phone' => $sharedPhone,
        ]], actor: $actor);

        return $customer;
    }
}
