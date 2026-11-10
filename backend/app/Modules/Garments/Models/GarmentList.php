<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GarmentList extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_item_id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'order_item_id' => 'integer',
            'current_version_id' => 'integer',
        ];
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return HasMany<GarmentListVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(GarmentListVersion::class);
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class, 'current_version_id');
    }
}
