<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class StoreCustomerRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->isMethod('POST') ? Gate::inspect('create', Customer::class) : Gate::inspect('update', $this->route('customer'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        /** @var User $user */
        $user = $this->user();

        return [
            ...CustomerRules::rules(partial: ! $creating),
            // The page association is fixed at creation (it scopes who may see the customer).
            'page_id' => $creating
                ? ['required', 'integer', 'exists:pages,id', static function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                    if (! $user->canAccessPage((int) $value)) {
                        $fail('Select a page assigned to you.');
                    }
                }]
                : ['prohibited'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id', static function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                if ($value !== null && ! $user->canAccessBranch((int) $value)) {
                    $fail('Select a branch assigned to you.');
                }
            }],
        ];
    }

    /** @return array<string, mixed> */
    public function attributesToSave(): array
    {
        $attributes = CustomerRules::toAttributes($this->validated());
        if ($this->has('branch_id')) {
            $attributes['branch_id'] = $this->validated('branch_id');
        }

        return $attributes;
    }
}
