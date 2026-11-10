<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Job specification / price edit. Status changes are never accepted here (prohibited). */
final class UpdateOrderRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('order'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...OrderRules::prohibited(),
            'branch_id' => ['prohibited'],
            'page_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'version' => ['required', 'integer', 'min:1'],
            ...OrderRules::specification(partial: true, user: $this->user()),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [...OrderRules::messages(), 'branch_id.prohibited' => 'The branch cannot change.', 'page_id.prohibited' => 'The page cannot change.', 'customer_id.prohibited' => 'The customer cannot change.'];
    }

    /** @return array<string, mixed> */
    public function toInput(): array
    {
        $v = $this->validated();
        $ints = ['version', 'order_type_id', 'product_type_id', 'gross_quantity', 'unit_price_minor', 'delivery_fee_minor', 'discount_minor'];
        foreach ($ints as $key) {
            if (array_key_exists($key, $v)) {
                $v[$key] = (int) $v[$key];
            }
        }

        return $v;
    }
}
