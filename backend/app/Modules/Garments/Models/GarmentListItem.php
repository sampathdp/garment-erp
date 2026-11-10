<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use App\Modules\Pricing\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One row of a name/number list. Player name and number are columns (numbers are strings:
 * "00" ≠ "0"); every other configurable field (sleeve, kids/adult, logo, bottom category ...)
 * is stored in garment_list_item_values.
 */
class GarmentListItem extends Model
{
    /** @var list<string> */
    protected $fillable = ['garment_list_version_id', 'line_no', 'player_name', 'number', 'size_id', 'quantity', 'notes'];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'integer',
            'size_id' => 'integer',
        ];
    }

    /** @return BelongsTo<GarmentListVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(GarmentListVersion::class, 'garment_list_version_id');
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** @return HasMany<GarmentListItemValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(GarmentListItemValue::class);
    }

    /** @return array<string, string> field key => stored value (values relation must be loaded) */
    public function valueMap(): array
    {
        return $this->values->mapWithKeys(fn (GarmentListItemValue $v): array => [$v->field_key => $v->value])->all();
    }
}
