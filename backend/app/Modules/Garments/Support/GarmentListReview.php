<?php

declare(strict_types=1);

namespace App\Modules\Garments\Support;

use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListVersion;
use Illuminate\Support\Collection;

/**
 * Whole-list review shown to the Page Owner before confirmation, and enforced on confirmation:
 *
 * errors (block confirmation)
 *  - the list is empty
 *  - total quantity ≠ the order's gross quantity
 *  - a required field is missing, or a stored value is no longer a valid option / size
 * warnings (must be acknowledged)
 *  - duplicate values in fields marked "warn on duplicate" (e.g. two players with number 10) [ASSUMPTION Q8]
 *  - identical rows
 */
final class GarmentListReview
{
    /**
     * @return array{
     *   rows: int, total_quantity: int, expected_quantity: int,
     *   by_size: list<array{size_id: int, size: string, quantity: int}>,
     *   price_groups: list<array{attributes: array<string, string>, label: string, quantity: int}>,
     *   errors: list<array{line_no: int|null, field: string|null, message: string}>,
     *   warnings: list<array{line_no: int|null, field: string|null, message: string}>,
     *   can_confirm: bool
     * }
     */
    public function review(GarmentListVersion $version, GarmentSchema $schema, int $expectedQuantity): array
    {
        /** @var Collection<int, GarmentListItem> $items */
        $items = $version->items()->with('values')->orderBy('line_no')->get();
        $errors = [];
        $warnings = [];

        if ($items->isEmpty()) {
            $errors[] = ['line_no' => null, 'field' => null, 'message' => 'The list has no rows.'];
        }

        foreach ($items as $item) {
            $values = $item->valueMap();
            if (! $schema->sizes->has($item->size_id)) {
                $errors[] = ['line_no' => $item->line_no, 'field' => 'size_id', 'message' => 'The size is no longer valid for this category.'];
            }
            foreach ($schema->fields as $field) {
                $value = $field->isColumn() ? $item->getAttribute($field->key) : ($values[$field->key] ?? null);
                if ($field->is_required && ($value === null || $value === '')) {
                    $errors[] = ['line_no' => $item->line_no, 'field' => $field->key, 'message' => "{$field->label} is required."];
                } elseif ($value !== null && $field->input_type->value === 'select' && ! array_key_exists($value, $schema->optionsFor($field))) {
                    $errors[] = ['line_no' => $item->line_no, 'field' => $field->key, 'message' => "{$field->label} \"{$value}\" is not an active option."];
                }
            }
        }

        $total = (int) $items->sum('quantity');
        if ($items->isNotEmpty() && $total !== $expectedQuantity) {
            $errors[] = ['line_no' => null, 'field' => 'quantity', 'message' => "The list totals {$total} garments but the order's gross quantity is {$expectedQuantity}."];
        }

        foreach ($schema->fields->filter(fn (GarmentFieldDefinition $f) => $f->warn_on_duplicate) as $field) {
            $groups = $items->groupBy(fn (GarmentListItem $i) => (string) ($field->isColumn() ? $i->getAttribute($field->key) : ($i->valueMap()[$field->key] ?? '')))
                ->reject(fn (Collection $g, string $value) => $value === '' || $g->count() < 2);
            foreach ($groups as $value => $group) {
                $warnings[] = ['line_no' => null, 'field' => $field->key, 'message' => "{$field->label} \"{$value}\" appears on rows ".$group->pluck('line_no')->implode(', ').'.'];
            }
        }

        $identical = $items->groupBy(fn (GarmentListItem $i) => json_encode([$i->size_id, $i->player_name, $i->number, $i->valueMap()]))
            ->filter(fn (Collection $g) => $g->count() > 1);
        foreach ($identical as $group) {
            $warnings[] = ['line_no' => null, 'field' => null, 'message' => 'Rows '.$group->pluck('line_no')->implode(', ').' are identical.'];
        }

        return [
            'rows' => $items->count(),
            'total_quantity' => $total,
            'expected_quantity' => $expectedQuantity,
            'by_size' => $items->groupBy('size_id')->map(fn (Collection $g, int|string $sizeId): array => [
                'size_id' => (int) $sizeId,
                'size' => $schema->sizes->get((int) $sizeId)->label ?? '?',
                'quantity' => (int) $g->sum('quantity'),
            ])->sortBy(fn (array $row) => $schema->sizes->keys()->search($row['size_id']))->values()->all(),
            'price_groups' => $this->priceGroups($items, $schema),
            'errors' => $errors,
            'warnings' => $warnings,
            'can_confirm' => $errors === [],
        ];
    }

    /**
     * Rows grouped by the fields that affect the price (sleeve type, kids/adult, bottom category ...).
     *
     * @param  Collection<int, GarmentListItem>  $items
     * @return list<array{attributes: array<string, string>, label: string, quantity: int}>
     */
    public function priceGroups(Collection $items, GarmentSchema $schema): array
    {
        $priced = $schema->pricedFields();
        $groups = [];
        foreach ($items as $item) {
            $values = $item->valueMap();
            $attributes = [];
            foreach ($priced as $field) {
                $value = $field->isColumn() ? $item->getAttribute($field->key) : ($values[$field->key] ?? null);
                if ($value !== null && $value !== '') {
                    $attributes[$field->key] = (string) $value;
                }
            }
            // Attributes are collected in the configured field order, so the label reads naturally.
            $key = json_encode($attributes);
            $described = collect($attributes)->map(fn (string $v, string $k) => $schema->display($schema->field($k) ?? throw new \LogicException, $v))->implode(', ');
            $groups[$key] ??= [
                'attributes' => $attributes,
                'label' => $described === '' ? $schema->productTypeName : "{$schema->productTypeName} — {$described}",
                'quantity' => 0,
            ];
            $groups[$key]['quantity'] += $item->quantity;
        }

        return array_values($groups);
    }
}
