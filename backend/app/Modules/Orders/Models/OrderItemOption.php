<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Pricing\Models\ProductOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemOption extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_item_id', 'product_option_id'];

    protected function casts(): array
    {
        return [
            'unit_surcharge_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class);
    }
}
