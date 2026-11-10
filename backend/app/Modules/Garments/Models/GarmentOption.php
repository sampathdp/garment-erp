<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentOption extends Model
{
    /** @var list<string> */
    protected $fillable = ['garment_option_set_id', 'code', 'label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<GarmentOptionSet, $this> */
    public function optionSet(): BelongsTo
    {
        return $this->belongsTo(GarmentOptionSet::class, 'garment_option_set_id');
    }
}
