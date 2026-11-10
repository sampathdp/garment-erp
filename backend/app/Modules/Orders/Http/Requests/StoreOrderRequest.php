<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Customers\Support\CustomerRules;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * New order form: CUSTOMER INFORMATION (existing customer_id, or customer.*) and
 * JOB SPECIFICATIONS, plus the agreed price used for the 30% advance.
 */
final class StoreOrderRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Order::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $creatingCustomer = ! $this->filled('customer_id');

        return [
            ...OrderRules::prohibited(),
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true), static function (string $a, mixed $value, Closure $fail) use ($user): void {
                if (! $user->canAccessBranch((int) $value)) {
                    $fail('Select a branch assigned to you.');
                }
            }],
            'page_id' => ['required', 'integer', Rule::exists('pages', 'id')->where('is_active', true), static function (string $a, mixed $value, Closure $fail) use ($user): void {
                if (! $user->canAccessPage((int) $value)) {
                    $fail('Select a page assigned to you.');
                }
            }],
            ...OrderRules::specification(partial: false, user: $user),
            'customer_id' => ['nullable', 'integer'],
            'customer' => $creatingCustomer ? ['required', 'array'] : ['prohibited'],
            ...($creatingCustomer ? CustomerRules::rules('customer.') : []),
            'submit' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...OrderRules::messages(),
            'customer.required' => 'Enter the customer information or select an existing customer.',
            'customer.prohibited' => 'Send either an existing customer or new customer details, not both.',
        ];
    }

    /** @return array<string, mixed> */
    public function toInput(): array
    {
        $v = $this->validated();

        return [
            'branch_id' => (int) $v['branch_id'],
            'page_id' => (int) $v['page_id'],
            'order_type_id' => (int) $v['order_type_id'],
            'due_date' => (string) $v['due_date'],
            'product_type_id' => (int) $v['product_type_id'],
            'gross_quantity' => (int) $v['gross_quantity'],
            'unit_price_minor' => (int) $v['unit_price_minor'],
            'delivery_fee_minor' => isset($v['delivery_fee_minor']) ? (int) $v['delivery_fee_minor'] : null,
            'discount_minor' => (int) ($v['discount_minor'] ?? 0),
            'customer_id' => isset($v['customer_id']) ? (int) $v['customer_id'] : null,
            'customer' => isset($v['customer']) ? CustomerRules::toAttributes($v['customer']) : null,
            'confirm_distinct' => (bool) ($v['customer']['confirm_distinct'] ?? false),
            'submit' => (bool) ($v['submit'] ?? false),
        ];
    }
}
