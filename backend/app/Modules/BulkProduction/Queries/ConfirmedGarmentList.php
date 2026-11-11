<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Queries;

use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Orders\Models\OrderItem;

/**
 * The confirmed (locked) name/number list version a bulk job refers to, read-only. Used by the
 * bulk screens and by the printing dashboard (Phase 06).
 */
final class ConfirmedGarmentList
{
    /** @return array{version: array<string, mixed>, schema: array<string, mixed>, items: list<array<string, mixed>>} */
    public function for(BulkJob $job, ?int $listVersionId = null): array
    {
        $version = GarmentListVersion::query()->with('locker:id,name')->findOrFail($listVersionId ?? $job->getAttribute('garment_list_version_id'));
        $schema = GarmentSchema::forProductType(self::orderItem($job)->product_type_id);
        $items = GarmentListItem::query()->where('garment_list_version_id', $version->id)->with('values')->orderBy('line_no')->get();

        return [
            'version' => ['id' => $version->id, 'version_no' => $version->version_no, 'status' => $version->status->value,
                'locked_by' => $version->locker?->name, 'locked_at' => $version->locked_at?->toIso8601ZuluString(), 'total_quantity' => $version->total_quantity],
            'schema' => $schema->toArray(),
            'items' => $items->map(fn (GarmentListItem $i): array => [
                'line_no' => $i->line_no, 'size' => $schema->sizes->get($i->size_id)?->label, 'quantity' => $i->quantity,
                'player_name' => $i->player_name, 'number' => $i->number, 'notes' => $i->notes, 'values' => $i->valueMap(),
            ])->values()->all(),
        ];
    }

    public static function orderItem(BulkJob $job): OrderItem
    {
        return OrderItem::query()->select(['id', 'order_id', 'product_type_id', 'quantity'])->where('order_id', $job->order_id)->orderBy('sort_order')->firstOrFail();
    }
}
