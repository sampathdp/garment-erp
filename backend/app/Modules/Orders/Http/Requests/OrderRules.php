<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Permissions;
use Closure;
use Illuminate\Validation\Rule;

/** Shared order-form rules. Status, numbers, totals and urgency are server-owned and prohibited. */
final class OrderRules
{
    /** Fields the client may never send: they are derived on the server. */
    public const SERVER_OWNED = [
        'status', 'order_no', 'payment_status', 'is_urgent', 'urgent_status', 'submitted_at', 'advance_percent',
        'advance_required_minor', 'verified_paid_minor', 'subtotal_minor', 'total_minor', 'tax_minor', 'total_quantity',
        'sales_owner_id', 'created_by',
    ];

    /** @return array<string, list<mixed>> */
    public static function specification(bool $partial, ?User $user = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'order_type_id' => [$required, 'integer', Rule::exists('order_types', 'id')->where('is_active', true)],
            'due_date' => [$required, 'date_format:Y-m-d', 'before_or_equal:'.now()->addYears(2)->toDateString()],
            'product_type_id' => [$required, 'integer', Rule::exists('product_types', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'gross_quantity' => [$required, 'integer', 'min:1', 'max:100000'],
            'unit_price_minor' => [$required, 'integer', 'min:0', 'max:100000000000'],
            'delivery_fee_minor' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000000'],
            // Discounts only when authorised (orders.discount).
            'discount_minor' => ['sometimes', 'integer', 'min:0', 'max:100000000000000', static function (string $a, mixed $value, Closure $fail) use ($user): void {
                if ((int) $value > 0 && ($user === null || ! $user->can(Permissions::ORDERS_DISCOUNT))) {
                    $fail('You are not authorised to give discounts.');
                }
            }],
        ];
    }

    /** @return array<string, list<string>> */
    public static function prohibited(): array
    {
        return array_fill_keys(self::SERVER_OWNED, ['prohibited']);
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            '*.prohibited' => 'This field is set by the system and cannot be sent.',
            'order_type_id.exists' => 'Select an active order type.',
            'product_type_id.exists' => 'Select an active product category.',
        ];
    }
}
