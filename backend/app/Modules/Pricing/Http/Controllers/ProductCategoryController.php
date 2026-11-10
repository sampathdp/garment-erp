<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\SizeChart;
use App\Support\Catalog\CatalogController;
use Illuminate\Database\Eloquent\Model;

/**
 * Product / item categories (Jersey, Arm Cut, Short, ...), stored as `product_types`.
 *
 * @extends CatalogController<ProductType>
 */
final class ProductCategoryController extends CatalogController
{
    protected function model(): string
    {
        return ProductType::class;
    }

    protected function auditPrefix(): string
    {
        return 'catalog.product_categories';
    }

    /** Size charts arrive with garment details (later phase); new categories start on the standard chart. */
    protected function defaults(): array
    {
        return ['size_chart_id' => SizeChart::query()->firstOrCreate(['name' => SizeChart::STANDARD])->id];
    }

    /** @return array<string, mixed> */
    protected function present(Model $entry): array
    {
        /** @var ProductType $entry */
        return ['id' => $entry->id, 'code' => $entry->code, 'name' => $entry->name, 'is_active' => $entry->is_active];
    }
}
