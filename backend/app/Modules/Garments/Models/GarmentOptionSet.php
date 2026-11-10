<?php

declare(strict_types=1);

namespace App\Modules\Garments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master data for select fields (sleeve types, kids/adult, bottom categories, ...). */
class GarmentOptionSet extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'name'];

    /** @return HasMany<GarmentOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(GarmentOption::class);
    }
}
