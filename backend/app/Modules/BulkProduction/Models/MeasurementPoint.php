<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Pricing\Models\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeasurementPoint extends Model
{
    /** @var list<string> */
    protected $fillable = ['product_type_id', 'code', 'name', 'unit'];

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }
}
