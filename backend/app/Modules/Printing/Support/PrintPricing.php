<?php

declare(strict_types=1);

namespace App\Modules\Printing\Support;

use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Support\GarmentListReview;
use App\Modules\Garments\Support\GarmentSchema;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Printing\Models\PrintJob;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Collection;

/**
 * Price groups for the invoice issued at Printing Completed (client decision 2026-11-25: the
 * price depends on the quantity actually printed).
 *
 * The groups (sleeve type, kids/adult ... — fields marked "affects price") come from the locked
 * name/number list the print job was prepared from. The operator confirms the printed quantity
 * per group (pre-filled with the list quantity); the page price rules then price each group, with
 * the quantity tier chosen from the TOTAL printed quantity (PriceQuote).
 */
final class PrintPricing
{
    public function __construct(private readonly GarmentListReview $review) {}

    /**
     * The list's price groups with the listed quantities.
     *
     * @return list<array{attributes: array<string, string>, label: string, quantity: int}>
     */
    public function listGroups(PrintJob $job): array
    {
        $versionId = $job->getAttribute('garment_list_version_id');
        if ($versionId === null) {
            return [];
        }
        $productTypeId = (int) OrderItem::query()->whereKey($job->getAttribute('order_item_id'))->value('product_type_id');
        /** @var Collection<int, GarmentListItem> $items */
        $items = GarmentListVersion::query()->findOrFail($versionId)->items()->with('values')->orderBy('line_no')->get();

        return $this->review->priceGroups($items, GarmentSchema::forProductType($productTypeId));
    }

    /**
     * Groups priced on the printed quantities.
     *
     *  - $printed (label => quantity) given: every list group must be present exactly once
     *    (0 allowed for a group that was not printed); the total must be at least 1.
     *  - $printed null: everything was printed as listed; $printedTotal must equal the list total.
     *
     * @param  array<string, int>|null  $printed
     * @return array{groups: list<array{attributes: array<string, string>, label: string, quantity: int}>, total: int}
     */
    public function printedGroups(PrintJob $job, ?array $printed, ?int $printedTotal): array
    {
        $groups = $this->listGroups($job);
        if ($groups === []) {
            throw ApiException::conflict('list_missing', 'The print job has no confirmed name/number list to price.');
        }

        if ($printed === null) {
            $listed = array_sum(array_column($groups, 'quantity'));
            if ($printedTotal !== $listed) {
                throw new ApiException(422, 'validation_failed', 'The printed quantity differs from the list. Enter the printed quantity per group.', [
                    'groups' => ["The list has {$listed} pieces. Enter how many were printed in each group."],
                ]);
            }

            return ['groups' => $groups, 'total' => $listed];
        }

        $labels = array_column($groups, 'label');
        $unknown = array_diff(array_keys($printed), $labels);
        $missing = array_diff($labels, array_keys($printed));
        if ($unknown !== [] || $missing !== []) {
            throw new ApiException(422, 'validation_failed', 'Enter the printed quantity for each group of the list.', [
                'groups' => [...array_map(fn (string $l) => "Unknown group: {$l}.", array_values($unknown)), ...array_map(fn (string $l) => "Missing group: {$l}.", array_values($missing))],
            ]);
        }

        $result = [];
        foreach ($groups as $group) {
            $quantity = $printed[$group['label']];
            if ($quantity > 0) {
                $result[] = [...$group, 'quantity' => $quantity];
            }
        }
        $total = array_sum(array_column($result, 'quantity'));
        if ($total < 1) {
            throw new ApiException(422, 'validation_failed', 'At least one piece must be printed.', ['groups' => ['At least one piece must be printed.']]);
        }

        return ['groups' => $result, 'total' => $total];
    }
}
