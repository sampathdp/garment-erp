<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentListItemValue extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['garment_list_item_id', 'field_key', 'value'];

    /** @return BelongsTo<GarmentListItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(GarmentListItem::class, 'garment_list_item_id');
    }
}
