<?php

declare(strict_types=1);

namespace App\Modules\Garments\Support;

use App\Modules\Garments\Enums\GarmentFieldType;
use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentOption;
use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\Size;
use Illuminate\Support\Collection;

/**
 * The configured list columns of one product category: its field definitions, the sizes of its
 * size chart and the active options of every select field. Nothing here is hard-coded; it all
 * comes from master data (garment setup screen).
 */
final class GarmentSchema
{
    /**
     * @param  Collection<int, GarmentFieldDefinition>  $fields
     * @param  Collection<int, Size>  $sizes  keyed by id
     * @param  array<int, array<string, string>>  $options  option set id => [code => label]
     */
    private function __construct(
        public readonly int $productTypeId,
        public readonly string $productTypeName,
        public readonly Collection $fields,
        public readonly Collection $sizes,
        public readonly array $options,
    ) {}

    public static function forProductType(int $productTypeId): self
    {
        $type = ProductType::query()->withTrashed()->select(['id', 'name', 'size_chart_id'])->findOrFail($productTypeId);
        $fields = GarmentFieldDefinition::query()->where('product_type_id', $productTypeId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->get();
        $sizes = Size::query()->where('size_chart_id', $type->size_chart_id)->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'label', 'sort_order'])->keyBy('id');

        $setIds = $fields->pluck('garment_option_set_id')->filter()->unique()->values()->all();
        $options = [];
        if ($setIds !== []) {
            foreach (GarmentOption::query()->whereIn('garment_option_set_id', $setIds)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $option) {
                $options[$option->garment_option_set_id][$option->code] = $option->label;
            }
        }

        return new self($type->id, $type->name, $fields, $sizes, $options);
    }

    public function field(string $key): ?GarmentFieldDefinition
    {
        return $this->fields->first(fn (GarmentFieldDefinition $f) => $f->key === $key);
    }

    /** @return array<string, string> */
    public function optionsFor(GarmentFieldDefinition $field): array
    {
        return $field->garment_option_set_id === null ? [] : ($this->options[$field->garment_option_set_id] ?? []);
    }

    /** Display text of a stored value. */
    public function display(GarmentFieldDefinition $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($field->input_type) {
            GarmentFieldType::Select => $this->optionsFor($field)[$value] ?? $value,
            GarmentFieldType::Boolean => $value === 'yes' ? 'Yes' : 'No',
            default => $value,
        };
    }

    /** @return Collection<int, GarmentFieldDefinition> fields whose value selects the price */
    public function pricedFields(): Collection
    {
        return $this->fields->filter(fn (GarmentFieldDefinition $f) => $f->affects_price)->values();
    }

    /** @return array<string, mixed> for the API */
    public function toArray(): array
    {
        return [
            'product_type' => ['id' => $this->productTypeId, 'name' => $this->productTypeName],
            'sizes' => $this->sizes->values()->map(fn (Size $s): array => ['id' => $s->id, 'code' => $s->code, 'label' => $s->label])->all(),
            'fields' => $this->fields->map(fn (GarmentFieldDefinition $f): array => [
                'key' => $f->key, 'label' => $f->label, 'input_type' => $f->input_type->value, 'is_required' => $f->is_required,
                'affects_price' => $f->affects_price, 'warn_on_duplicate' => $f->warn_on_duplicate, 'max_length' => $f->max_length,
                'options' => collect($this->optionsFor($f))->map(fn (string $label, string $code): array => ['code' => $code, 'label' => $label])->values()->all(),
            ])->all(),
        ];
    }
}
