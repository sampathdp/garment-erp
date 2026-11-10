<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Queries;

use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Page-specific pricing engine.
 *
 * 1. Price list: the page's active list effective on the pricing date (valid_from ≤ date ≤ valid_to).
 * 2. For every group of list rows with the same price attributes (sleeve type, kids/adult, bottom
 *    category ... — fields marked "affects price"), the candidate rules are those of the product
 *    category whose material / branch are empty or equal to the order's, whose quantity tier
 *    contains the order item's TOTAL quantity, and all of whose conditions match the group.
 * 3. The most specific candidate wins (conditions + material + branch), then the highest quantity
 *    tier reached; a remaining tie with different prices is reported as ambiguous, never guessed.
 *
 * Integer minor units only. A group without a rule makes the whole quote fail (409 pricing_missing):
 * an invoice is never issued with a missing price.
 */
final class PriceQuote
{
    /**
     * @param  list<array{attributes: array<string, string>, label: string, quantity: int}>  $groups
     * @return array{price_list: PriceList, lines: list<array{description: string, quantity: int, unit_price_minor: int, line_total_minor: int, price_list_item_id: int}>}
     */
    public function quote(int $pageId, int $branchId, int $productTypeId, ?int $materialId, CarbonImmutable $date, array $groups): array
    {
        $list = PriceList::query()->where('page_id', $pageId)->where('is_active', true)
            ->whereDate('valid_from', '<=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date->toDateString()))
            ->orderByDesc('valid_from')->first()
            ?? throw new ApiException(409, 'pricing_missing', 'No active price list for this page on '.$date->toDateString().'.', ['pricing' => ['Ask an administrator to configure the page price list.']]);

        $totalQuantity = array_sum(array_column($groups, 'quantity'));

        /** @var Collection<int, PriceListItem> $rules */
        $rules = PriceListItem::query()->where('price_list_id', $list->id)->where('product_type_id', $productTypeId)
            ->where(fn ($q) => $q->whereNull('material_id')->when($materialId !== null, fn ($m) => $m->orWhere('material_id', $materialId)))
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->where('min_qty', '<=', $totalQuantity)
            ->where(fn ($q) => $q->whereNull('max_qty')->orWhere('max_qty', '>=', $totalQuantity))
            ->with('conditions')->orderBy('id')->get();

        $lines = [];
        $missing = [];
        foreach ($groups as $group) {
            $rule = $this->pick($rules, $group['attributes'], $group['label'], $missing);
            if ($rule === null) {
                continue;
            }
            $lines[] = [
                'description' => $group['label'],
                'quantity' => $group['quantity'],
                'unit_price_minor' => $rule->unit_price_minor,
                'line_total_minor' => $group['quantity'] * $rule->unit_price_minor,
                'price_list_item_id' => $rule->id,
            ];
        }

        if ($missing !== []) {
            throw new ApiException(409, 'pricing_missing', 'Some garments have no price in "'.$list->name.'".', ['pricing' => $missing]);
        }

        return ['price_list' => $list, 'lines' => $lines];
    }

    /**
     * @param  Collection<int, PriceListItem>  $rules
     * @param  array<string, string>  $attributes
     * @param  list<string>  $missing
     */
    private function pick(Collection $rules, array $attributes, string $label, array &$missing): ?PriceListItem
    {
        $matching = $rules->filter(fn (PriceListItem $r) => $r->conditions->every(
            fn (PriceListItemCondition $c) => ($attributes[$c->field_key] ?? null) === $c->option_code,
        ));

        if ($matching->isEmpty()) {
            $missing[] = "No price for {$label}.";

            return null;
        }

        $score = fn (PriceListItem $r): int => $r->conditions->count() * 4 + ($r->material_id !== null ? 2 : 0) + ($r->branch_id !== null ? 1 : 0);
        $best = $matching->max($score);
        $winners = $matching->filter(fn (PriceListItem $r) => $score($r) === $best);
        // Same specificity: the highest quantity tier reached wins (e.g. "10+" over "1+").
        $tier = $winners->max('min_qty');
        $winners = $winners->filter(fn (PriceListItem $r) => $r->min_qty === $tier);

        if ($winners->pluck('unit_price_minor')->unique()->count() > 1) {
            $missing[] = "Several equally specific prices match {$label}: ".$winners->map(fn (PriceListItem $r) => $r->label ?? "#{$r->id}")->implode(', ').'.';

            return null;
        }

        return $winners->first();
    }
}
