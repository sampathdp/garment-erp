<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\Pricing\Models\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configurable job-level bulk measurement (bulk meter, patch meter, collar meter, ...).
 * product_type_id null = applies to every product category.
 *
 * @property string $key
 * @property string $label
 * @property string $unit
 * @property int|null $product_type_id
 * @property string $min_value
 * @property string $max_value
 * @property int $decimals
 * @property bool $is_required
 * @property bool $is_system
 * @property bool $show_on_job_card
 * @property int $sort_order
 * @property bool $is_active
 */
class BulkMeasurementField extends Model
{
    public const BULK_METER = 'bulk_meter';

    public const PATCH_METER = 'patch_meter';

    /** @var list<string> */
    protected $fillable = ['key', 'label', 'unit', 'product_type_id', 'min_value', 'max_value', 'decimals', 'is_required', 'show_on_job_card', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return [
            'product_type_id' => 'integer',
            'min_value' => 'decimal:3',
            'max_value' => 'decimal:3',
            'decimals' => 'integer',
            'is_required' => 'boolean',
            'is_system' => 'boolean',
            'show_on_job_card' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Active fields for a product category (category-specific fields win over generic ones with the same key).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApplicableTo(Builder $query, int $productTypeId): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('product_type_id')->orWhere('product_type_id', $productTypeId))
            ->orderBy('sort_order')->orderBy('id');
    }

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }
}
