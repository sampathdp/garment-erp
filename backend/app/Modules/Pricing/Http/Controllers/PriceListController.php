<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Pricing\Actions\SavePriceList;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Support\Http\ApiResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Page-specific price lists and their rules (pricing tables supplied by the client are entered here). */
final class PriceListController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PriceList::class);
        $request->validate(['filter.page_id' => ['sometimes', 'integer']]);
        /** @var User $user */
        $user = $request->user();

        $lists = PriceList::query()->visibleTo($user)->with('page:id,code,name')->withCount('items')
            ->when($request->filled('filter.page_id'), fn ($q) => $q->where('page_id', (int) $request->input('filter.page_id')))
            ->orderBy('page_id')->orderByDesc('valid_from')->limit(200)->get();

        return ApiResponse::data(['items' => $lists->map(fn (PriceList $l) => $this->present($l))->all()]);
    }

    public function show(PriceList $priceList): JsonResponse
    {
        Gate::authorize('view', $priceList);

        return ApiResponse::data($this->present($priceList->load(['page:id,code,name', 'items.conditions', 'items.productType:id,name', 'items.material:id,name', 'items.branch:id,code']), true));
    }

    public function store(Request $request, SavePriceList $action): JsonResponse
    {
        Gate::authorize('create', PriceList::class);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'page_id' => ['required', 'integer', 'exists:pages,id', static function (string $a, mixed $v, Closure $fail) use ($user): void {
                if (! $user->canAccessPage((int) $v)) {
                    $fail('Select a page assigned to you.');
                }
            }],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:valid_from'],
            'copy_from_id' => ['sometimes', 'nullable', 'integer', Rule::exists('price_lists', 'id')->where('page_id', $request->integer('page_id'))],
        ]);
        $list = $action->create($user, [
            'page_id' => (int) $data['page_id'], 'name' => $data['name'], 'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'] ?? null, 'copy_from_id' => isset($data['copy_from_id']) ? (int) $data['copy_from_id'] : null,
        ]);

        return ApiResponse::data($this->present($list->load('page:id,code,name')->loadCount('items')), 201);
    }

    public function update(Request $request, PriceList $priceList, SavePriceList $action): JsonResponse
    {
        Gate::authorize('update', $priceList);
        $data = $request->validate([
            'page_id' => ['prohibited'], 'valid_from' => ['prohibited'],
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'valid_to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data($this->present($action->update($user, $priceList, $data)->load('page:id,code,name')->loadCount('items')));
    }

    public function storeRule(Request $request, PriceList $priceList, SavePriceList $action): JsonResponse
    {
        Gate::authorize('update', $priceList);
        /** @var User $user */
        $user = $request->user();
        $rule = $action->saveRule($user, $priceList, null, $this->ruleData($request));

        return ApiResponse::data(['id' => $rule->id], 201);
    }

    public function updateRule(Request $request, PriceList $priceList, PriceListItem $rule, SavePriceList $action): JsonResponse
    {
        Gate::authorize('update', $priceList);
        abort_unless($rule->price_list_id === $priceList->id, 404);
        /** @var User $user */
        $user = $request->user();
        $action->saveRule($user, $priceList, $rule, $this->ruleData($request));

        return ApiResponse::data(['id' => $rule->id]);
    }

    public function destroyRule(Request $request, PriceList $priceList, PriceListItem $rule, SavePriceList $action): Response
    {
        Gate::authorize('update', $priceList);
        abort_unless($rule->price_list_id === $priceList->id, 404);
        /** @var User $user */
        $user = $request->user();
        $action->deleteRule($user, $priceList, $rule);

        return response()->noContent();
    }

    /** @return array{product_type_id: int, material_id: int|null, branch_id: int|null, label: string|null, min_qty: int, max_qty: int|null, unit_price_minor: int, conditions: list<array{field_key: string, option_code: string}>} */
    private function ruleData(Request $request): array
    {
        $data = $request->validate([
            'product_type_id' => ['required', 'integer', 'exists:product_types,id'],
            'material_id' => ['sometimes', 'nullable', 'integer', 'exists:materials,id'],
            'branch_id' => ['sometimes', 'nullable', 'integer', 'exists:branches,id'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'min_qty' => ['required', 'integer', 'min:1', 'max:1000000'],
            'max_qty' => ['sometimes', 'nullable', 'integer', 'gte:min_qty', 'max:1000000'],
            'unit_price_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'conditions' => ['sometimes', 'array', 'max:10'],
            'conditions.*.field_key' => ['required', 'string', 'max:40', 'distinct', Rule::exists('garment_field_definitions', 'key')->where('product_type_id', $request->integer('product_type_id'))->where('affects_price', true)],
            'conditions.*.option_code' => ['required', 'string', 'max:40'],
        ], ['conditions.*.field_key.exists' => 'Conditions can only use fields of this category marked "affects price".']);

        return [
            'product_type_id' => (int) $data['product_type_id'],
            'material_id' => isset($data['material_id']) ? (int) $data['material_id'] : null,
            'branch_id' => isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            'label' => $data['label'] ?? null,
            'min_qty' => (int) $data['min_qty'],
            'max_qty' => isset($data['max_qty']) ? (int) $data['max_qty'] : null,
            'unit_price_minor' => (int) $data['unit_price_minor'],
            'conditions' => array_values(array_map(fn (array $c) => ['field_key' => (string) $c['field_key'], 'option_code' => (string) $c['option_code']], $data['conditions'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private function present(PriceList $list, bool $detail = false): array
    {
        return [
            'id' => $list->id,
            'page' => $list->relationLoaded('page') ? ['id' => $list->page->id, 'code' => $list->page->code, 'name' => $list->page->name] : null,
            'name' => $list->name,
            'currency' => $list->currency,
            'valid_from' => $list->valid_from->format('Y-m-d'),
            'valid_to' => $list->valid_to?->format('Y-m-d'),
            'is_active' => $list->is_active,
            'in_use' => SavePriceList::inUse($list),
            'rules_count' => $list->items_count ?? ($list->relationLoaded('items') ? $list->items->count() : null),
            'rules' => $detail ? $list->items->sortBy([['product_type_id', 'asc'], ['min_qty', 'asc']])->values()->map(fn (PriceListItem $r): array => [
                'id' => $r->id, 'product_type' => ['id' => $r->productType->id, 'name' => $r->productType->name],
                'material' => $r->material ? ['id' => $r->material->id, 'name' => $r->material->name] : null,
                'branch' => $r->branch ? ['id' => $r->branch->id, 'code' => $r->branch->code] : null,
                'label' => $r->label, 'min_qty' => $r->min_qty, 'max_qty' => $r->max_qty, 'unit_price_minor' => $r->unit_price_minor,
                'conditions' => $r->conditions->map(fn (PriceListItemCondition $c): array => ['field_key' => $c->field_key, 'option_code' => $c->option_code])->all(),
            ])->all() : null,
            'abilities' => ['update' => Gate::allows('update', $list)],
        ];
    }
}
