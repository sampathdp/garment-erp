<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Pricing\Models\Material;
use App\Support\Catalog\CatalogController;
use Illuminate\Database\Eloquent\Model;

/**
 * Fabric types / materials master data (selected as "Fabric Type" in the job details).
 *
 * @extends CatalogController<Material>
 */
final class MaterialController extends CatalogController
{
    protected function model(): string
    {
        return Material::class;
    }

    protected function auditPrefix(): string
    {
        return 'catalog.materials';
    }

    /** @return array<string, mixed> */
    protected function present(Model $entry): array
    {
        /** @var Material $entry */
        return ['id' => $entry->id, 'code' => $entry->code, 'name' => $entry->name, 'is_active' => $entry->is_active];
    }
}
