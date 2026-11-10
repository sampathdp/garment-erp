<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Design\Models\DesignTask;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Pricing\Models\Material;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItem extends Model
{
    /**
     * Prices are snapshots written by the pricing Action, not mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = ['order_id', 'product_type_id', 'material_id', 'description', 'quantity', 'sort_order'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'order_id' => 'integer',
            'product_type_id' => 'integer',
            'unit_price_minor' => 'integer',
            'options_unit_minor' => 'integer',
            'line_total_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
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

    /** @return BelongsTo<PriceListItem, $this> */
    public function priceListItem(): BelongsTo
    {
        return $this->belongsTo(PriceListItem::class);
    }

    /** @return HasMany<OrderItemOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }

    /** @return HasOne<DesignTask, $this> */
    public function designTask(): HasOne
    {
        return $this->hasOne(DesignTask::class);
    }

    /** @return HasOne<GarmentList, $this> */
    public function garmentList(): HasOne
    {
        return $this->hasOne(GarmentList::class);
    }
}
