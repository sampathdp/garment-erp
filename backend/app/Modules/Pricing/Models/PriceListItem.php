<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\Organizations\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pricing rule: product category × optional material × optional branch × quantity tier ×
 * optional attribute conditions (sleeve type, kids/adult, bottom category ... from the garment list).
 */
class PriceListItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['price_list_id', 'product_type_id', 'material_id', 'branch_id', 'label', 'min_qty', 'max_qty', 'unit_price_minor'];

    protected function casts(): array
    {
        return [
            'price_list_id' => 'integer',
            'product_type_id' => 'integer',
            'material_id' => 'integer',
            'branch_id' => 'integer',
            'min_qty' => 'integer',
            'max_qty' => 'integer',
            'unit_price_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /** @return BelongsTo<Material, $this> */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<PriceListItemCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(PriceListItemCondition::class);
    }
}
