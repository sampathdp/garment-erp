<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Models;

use App\Modules\BulkProduction\Models\MeasurementPoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'size_chart_id', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<SizeChart, $this> */
    public function sizeChart(): BelongsTo
    {
        return $this->belongsTo(SizeChart::class);
    }

    /** @return HasMany<ProductOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class);
    }

    /** @return HasMany<MeasurementPoint, $this> */
    public function measurementPoints(): HasMany
    {
        return $this->hasMany(MeasurementPoint::class);
    }
}
