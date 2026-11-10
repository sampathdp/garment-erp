<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceListOptionPrice extends Model
{
    /** @var list<string> */
    protected $fillable = ['price_list_id', 'product_option_id', 'unit_surcharge_minor'];

    protected function casts(): array
    {
        return [
            'unit_surcharge_minor' => 'integer',
        ];
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /** @return BelongsTo<ProductOption, $this> */
    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class);
    }
}
