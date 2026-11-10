<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\Size;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SizeSpec extends Model
{
    /** @var list<string> */
    protected $fillable = ['product_type_id', 'size_id', 'measurement_point_id', 'target_value', 'tolerance'];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:2',
            'tolerance' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    /** @return BelongsTo<MeasurementPoint, $this> */
    public function measurementPoint(): BelongsTo
    {
        return $this->belongsTo(MeasurementPoint::class);
    }
}
