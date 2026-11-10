<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use App\Modules\Garments\Enums\GarmentFieldType;
use App\Modules\Pricing\Models\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A configurable list column for one product category. `player_name` and `number` are stored
 * in item columns; every other key is stored in garment_list_item_values. Size and quantity
 * are always present (sizes come from the category's size chart).
 *
 * @property GarmentFieldType $input_type
 */
class GarmentFieldDefinition extends Model
{
    public const COLUMN_KEYS = ['player_name', 'number'];

    /** @var list<string> */
    protected $fillable = ['product_type_id', 'key', 'label', 'input_type', 'garment_option_set_id', 'is_required', 'affects_price', 'warn_on_duplicate', 'max_length', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'input_type' => GarmentFieldType::class,
            'is_required' => 'boolean',
            'affects_price' => 'boolean',
            'warn_on_duplicate' => 'boolean',
            'is_active' => 'boolean',
            'max_length' => 'integer',
            'sort_order' => 'integer',
            'garment_option_set_id' => 'integer',
        ];
    }

    public function isColumn(): bool
    {
        return in_array($this->key, self::COLUMN_KEYS, true);
    }

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /** @return BelongsTo<GarmentOptionSet, $this> */
    public function optionSet(): BelongsTo
    {
        return $this->belongsTo(GarmentOptionSet::class, 'garment_option_set_id');
    }
}
