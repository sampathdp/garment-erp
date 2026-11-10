<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SizeChart extends Model
{
    /** Default chart for categories created before size charts are configured. */
    public const STANDARD = 'Standard';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return HasMany<Size, $this> */
    public function sizes(): HasMany
    {
        return $this->hasMany(Size::class);
    }
}
