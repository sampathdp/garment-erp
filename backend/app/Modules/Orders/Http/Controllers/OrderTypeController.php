<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Orders\Models\OrderType;
use App\Support\Catalog\CatalogController;
use Illuminate\Database\Eloquent\Model;

/** @extends CatalogController<OrderType> */
final class OrderTypeController extends CatalogController
{
    protected function model(): string
    {
        return OrderType::class;
    }

    protected function auditPrefix(): string
    {
        return 'catalog.order_types';
    }

    protected function sortColumn(): string
    {
        return 'sort_order';
    }

    /** @return array<string, mixed> */
    protected function present(Model $entry): array
    {
        /** @var OrderType $entry */
        return ['id' => $entry->id, 'code' => $entry->code, 'name' => $entry->name, 'is_active' => $entry->is_active];
    }
}
