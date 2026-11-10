<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The rule applies only to list rows whose `field_key` has the value `option_code`. */
class PriceListItemCondition extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['price_list_item_id', 'field_key', 'option_code'];

    /** @return BelongsTo<PriceListItem, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PriceListItem::class, 'price_list_item_id');
    }
}
