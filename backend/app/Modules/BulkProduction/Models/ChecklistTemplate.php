<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Models;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\Pricing\Models\ProductType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ChecklistStage $stage
 * @property int|null $product_type_id
 * @property int $version_no
 * @property bool $is_active
 */
class ChecklistTemplate extends Model
{
    /** @var list<string> */
    protected $fillable = ['stage', 'product_type_id', 'name', 'version_no', 'is_active'];

    protected function casts(): array
    {
        return [
            'stage' => ChecklistStage::class,
            'version_no' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<ProductType, $this> */
    public function productType(): BelongsTo
    {
        return $this->belongsTo(ProductType::class);
    }

    /** @return HasMany<ChecklistTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistTemplateItem::class);
    }
}
