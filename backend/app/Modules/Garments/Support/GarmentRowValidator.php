<?php

declare(strict_types=1);

namespace App\Modules\Garments\Support;

use App\Modules\Garments\Enums\GarmentFieldType;
use App\Modules\Garments\Models\GarmentFieldDefinition;

/**
 * Normalises and type-checks one list row against the category schema (on entry and import).
 * Completeness (required fields, totals, duplicates) is checked at confirmation by GarmentListReview.
 *
 * Input row: size_id, quantity, player_name?, number?, notes?, values: {field_key: value}
 * Output: [normalised row, errors (field => message)]
 */
final class GarmentRowValidator
{
    public const MAX_QUANTITY = 9999;

    /**
     * @param  array<string, mixed>  $row
     * @return array{0: array{size_id: int|null, quantity: int, player_name: string|null, number: string|null, notes: string|null, values: array<string, string>}, 1: array<string, string>}
     */
    public function validate(array $row, GarmentSchema $schema): array
    {
        $errors = [];

        $sizeId = $row['size_id'] ?? null;
        $sizeId = is_numeric($sizeId) ? (int) $sizeId : null;
        if ($sizeId === null || ! $schema->sizes->has($sizeId)) {
            $errors['size_id'] = $schema->sizes->isEmpty() ? 'No sizes are configured for this category.' : 'Select a size of this category.';
        }

        $quantity = $row['quantity'] ?? 1;
        if (! is_numeric($quantity) || (int) $quantity != $quantity || (int) $quantity < 1 || (int) $quantity > self::MAX_QUANTITY) {
            $errors['quantity'] = 'Quantity must be a whole number from 1 to '.self::MAX_QUANTITY.'.';
            $quantity = 1;
        }

        $normalised = ['size_id' => $sizeId, 'quantity' => (int) $quantity, 'player_name' => null, 'number' => null, 'notes' => self::text($row['notes'] ?? null, 255), 'values' => []];
        $values = is_array($row['values'] ?? null) ? $row['values'] : [];

        foreach ($schema->fields as $field) {
            $raw = $field->isColumn() ? ($row[$field->key] ?? null) : ($values[$field->key] ?? null);
            [$value, $error] = $this->value($field, $raw, $schema);
            if ($error !== null) {
                $errors[$field->key] = $error;
            }
            if ($field->isColumn()) {
                $normalised[$field->key] = $value;
            } elseif ($value !== null) {
                $normalised['values'][$field->key] = $value;
            }
        }

        foreach (array_keys($values) as $key) {
            if ($schema->field((string) $key) === null || in_array($key, GarmentFieldDefinition::COLUMN_KEYS, true)) {
                $errors['values.'.$key] = 'Unknown field for this category.';
            }
        }

        return [$normalised, $errors];
    }

    /** @return array{0: string|null, 1: string|null} */
    private function value(GarmentFieldDefinition $field, mixed $raw, GarmentSchema $schema): array
    {
        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return [null, null];
        }
        if (! is_scalar($raw)) {
            return [null, "{$field->label} is invalid."];
        }

        $value = trim((string) $raw);
        $max = min($field->max_length ?? 120, $field->key === 'number' ? 5 : ($field->key === 'player_name' ? 60 : 120));

        return match ($field->input_type) {
            GarmentFieldType::Select => array_key_exists($value, $schema->optionsFor($field))
                ? [$value, null]
                : (($code = array_search(mb_strtolower($value), array_map('mb_strtolower', $schema->optionsFor($field)), true)) !== false
                    ? [(string) $code, null] // label typed or imported instead of the code
                    : [null, "Choose a valid {$field->label}."]),
            GarmentFieldType::Boolean => match (mb_strtolower($value)) {
                '1', 'yes', 'y', 'true' => ['yes', null],
                '0', 'no', 'n', 'false' => ['no', null],
                default => [null, "{$field->label} must be yes or no."],
            },
            // Numbers stay strings: "00" and "0" are different shirt numbers.
            GarmentFieldType::Number => preg_match('/^\d+$/', $value) === 1 && mb_strlen($value) <= $max
                ? [$value, null] : [null, "{$field->label} must be digits only (max {$max})."],
            GarmentFieldType::Text => mb_strlen($value) <= $max && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                ? [$value, null] : [null, "{$field->label} must be at most {$max} characters."],
        };
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
