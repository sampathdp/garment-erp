<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Support\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Price lists with effective dates and preserved history:
 *  - lists of one page never overlap; a new list starting later closes an open-ended earlier one
 *    the day before (so a pricing change is "a new list from date X");
 *  - a list used by an issued invoice is frozen (its rules cannot change), so history is
 *    reproducible; change prices by creating a new list (optionally copying the rules).
 */
final class SavePriceList
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array{page_id: int, name: string, valid_from: string, valid_to: string|null, copy_from_id: int|null}  $data */
    public function create(User $actor, array $data): PriceList
    {
        return DB::transaction(function () use ($actor, $data): PriceList {
            $from = CarbonImmutable::parse($data['valid_from']);
            // Close an open-ended earlier list the day before the new one starts.
            PriceList::query()->where('page_id', $data['page_id'])->where('is_active', true)->whereNull('valid_to')
                ->whereDate('valid_from', '<', $from->toDateString())->lockForUpdate()->get()
                ->each(function (PriceList $old) use ($from, $actor): void {
                    $old->forceFill(['valid_to' => $from->subDay()->toDateString()])->save();
                    $this->audit->record('pricing.price_list_closed', $old, ['after' => ['valid_to' => $old->valid_to?->toDateString()]], actor: $actor);
                });
            $this->assertNoOverlap($data['page_id'], $from, $data['valid_to'] === null ? null : CarbonImmutable::parse($data['valid_to']), null);

            $list = PriceList::query()->create([
                'page_id' => $data['page_id'], 'name' => $data['name'], 'currency' => 'LKR', 'valid_from' => $from->toDateString(),
                'valid_to' => $data['valid_to'], 'is_active' => true, 'created_by' => $actor->id,
            ]);

            if ($data['copy_from_id'] !== null) {
                $source = PriceList::query()->with('items.conditions')->findOrFail($data['copy_from_id']);
                foreach ($source->items as $rule) {
                    $copy = PriceListItem::query()->create([...$rule->only(['product_type_id', 'material_id', 'branch_id', 'label', 'min_qty', 'max_qty', 'unit_price_minor']), 'price_list_id' => $list->id]);
                    foreach ($rule->conditions as $condition) {
                        PriceListItemCondition::query()->create(['price_list_item_id' => $copy->id, 'field_key' => $condition->field_key, 'option_code' => $condition->option_code]);
                    }
                }
            }

            $this->audit->record('pricing.price_list_created', $list, ['after' => $data], actor: $actor);

            return $list;
        });
    }

    /** @param  array<string, mixed>  $data  name, valid_to, is_active */
    public function update(User $actor, PriceList $list, array $data): PriceList
    {
        return DB::transaction(function () use ($actor, $list, $data): PriceList {
            $list = PriceList::query()->lockForUpdate()->findOrFail($list->id);
            if (array_key_exists('valid_to', $data)) {
                $this->assertNoOverlap($list->page_id, CarbonImmutable::instance($list->valid_from), $data['valid_to'] === null ? null : CarbonImmutable::parse($data['valid_to']), $list->id);
            }
            $before = $list->only(array_keys($data));
            $list->fill($data)->save();
            $this->audit->record('pricing.price_list_updated', $list, ['before' => $before, 'after' => $data], actor: $actor);

            return $list;
        });
    }

    /**
     * @param  array{product_type_id: int, material_id: int|null, branch_id: int|null, label: string|null, min_qty: int, max_qty: int|null, unit_price_minor: int, conditions: list<array{field_key: string, option_code: string}>}  $data
     */
    public function saveRule(User $actor, PriceList $list, ?PriceListItem $rule, array $data): PriceListItem
    {
        return DB::transaction(function () use ($actor, $list, $rule, $data): PriceListItem {
            $list = PriceList::query()->lockForUpdate()->findOrFail($list->id);
            $this->assertNotInUse($list);
            $conditions = collect($data['conditions'])->sortBy('field_key')->values();

            $duplicate = PriceListItem::query()->where('price_list_id', $list->id)->where('product_type_id', $data['product_type_id'])
                ->where('material_id', $data['material_id'])->where('branch_id', $data['branch_id'])->where('min_qty', $data['min_qty'])
                ->when($rule !== null, fn ($q) => $q->whereKeyNot($rule->id))->with('conditions')->get()
                ->first(fn (PriceListItem $r) => $r->conditions->sortBy('field_key')->map(fn ($c) => [$c->field_key, $c->option_code])->values()->all()
                    === $conditions->map(fn ($c) => [$c['field_key'], $c['option_code']])->all());
            if ($duplicate !== null) {
                throw new ApiException(422, 'validation_failed', 'An identical rule already exists.', ['min_qty' => ['An identical rule (same category, material, branch, tier and conditions) already exists.']]);
            }

            $rule ??= new PriceListItem(['price_list_id' => $list->id]);
            $before = $rule->exists ? $rule->only(array_keys($data)) : [];
            $rule->fill(collect($data)->except('conditions')->all())->save();
            PriceListItemCondition::query()->where('price_list_item_id', $rule->id)->delete();
            foreach ($conditions as $condition) {
                PriceListItemCondition::query()->create(['price_list_item_id' => $rule->id, ...$condition]);
            }
            $this->audit->record('pricing.rule_saved', $rule, ['before' => $before, 'after' => $data], actor: $actor);

            return $rule;
        });
    }

    public function deleteRule(User $actor, PriceList $list, PriceListItem $rule): void
    {
        DB::transaction(function () use ($actor, $list, $rule): void {
            $this->assertNotInUse(PriceList::query()->lockForUpdate()->findOrFail($list->id));
            $rule->delete();
            $this->audit->record('pricing.rule_deleted', $rule, ['before' => $rule->only(['product_type_id', 'min_qty', 'unit_price_minor'])], actor: $actor);
        });
    }

    public static function inUse(PriceList $list): bool
    {
        return Invoice::query()->where('price_list_id', $list->id)->exists();
    }

    private function assertNotInUse(PriceList $list): void
    {
        if (self::inUse($list)) {
            throw ApiException::conflict('price_list_in_use', 'This price list is used by issued invoices and cannot change. Create a new price list with a new effective date.');
        }
    }

    private function assertNoOverlap(int $pageId, CarbonImmutable $from, ?CarbonImmutable $to, ?int $ignoreId): void
    {
        if ($to !== null && $to < $from) {
            throw new ApiException(422, 'validation_failed', 'The end date is before the start date.', ['valid_to' => ['The end date is before the start date.']]);
        }

        $overlap = PriceList::query()->where('page_id', $pageId)->where('is_active', true)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->whereDate('valid_from', '<=', $to->toDateString()))
            ->exists();
        if ($overlap) {
            throw new ApiException(422, 'validation_failed', 'The dates overlap another active price list of this page.', ['valid_from' => ['The dates overlap another active price list of this page.']]);
        }
    }
}
