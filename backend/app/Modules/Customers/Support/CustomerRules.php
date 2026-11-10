<?php

declare(strict_types=1);

namespace App\Modules\Customers\Support;

use App\Support\Validation\PhoneNumber;

/** Validation rules shared by the customer screens and the inline customer on the order form. */
final class CustomerRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(string $prefix = '', bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            $prefix.'name' => [$required, 'string', 'min:2', 'max:150'],
            $prefix.'phone' => [$required, 'string', 'max:20', PhoneNumber::rule()],
            $prefix.'whatsapp' => [$required, 'string', 'max:20', PhoneNumber::rule()],
            $prefix.'other_phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
            $prefix.'address' => ['sometimes', 'nullable', 'string', 'max:200'],
            $prefix.'email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:191'],
            $prefix.'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Set when staff confirm that a customer sharing this phone number is a different person.
            $prefix.'confirm_distinct' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Maps validated API fields to columns (phones normalised to E.164).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function toAttributes(array $input): array
    {
        $map = ['name' => 'name', 'address' => 'address_line1', 'email' => 'email', 'notes' => 'notes'];
        $attributes = [];
        foreach ($map as $field => $column) {
            if (array_key_exists($field, $input)) {
                $value = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                $attributes[$column] = $value === '' ? null : $value;
            }
        }
        foreach (['phone' => 'phone_e164', 'whatsapp' => 'whatsapp_e164', 'other_phone' => 'alt_phone_e164'] as $field => $column) {
            if (array_key_exists($field, $input)) {
                $attributes[$column] = PhoneNumber::normalize($input[$field]);
            }
        }

        return $attributes;
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
