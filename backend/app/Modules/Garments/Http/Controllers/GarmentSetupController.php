<?php

declare(strict_types=1);

namespace App\Modules\Garments\Http\Controllers;

use App\Modules\Audit\AuditLogger;
use App\Modules\Garments\Enums\GarmentFieldType;
use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentOption;
use App\Modules\Garments\Models\GarmentOptionSet;
use App\Modules\Identity\Models\User;
use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\Size;
use App\Modules\Pricing\Models\SizeChart;
use App\Support\Exceptions\ApiException;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Master data for name/number lists: per-category field definitions, option sets (sleeve types,
 * kids/adult, bottom categories ...), size charts and sizes. Nothing about sizes or categories is
 * hard-coded. Reading: garment-setup.view; writing: garment-setup.manage (pricing.manage).
 * Entries are deactivated, never deleted, because confirmed lists reference them.
 */
final class GarmentSetupController
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        Gate::authorize('garment-setup.view');

        return ApiResponse::data([
            'categories' => ProductType::query()->orderBy('name')->get(['id', 'code', 'name', 'size_chart_id', 'is_active'])->map(fn (ProductType $t): array => [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'size_chart_id' => $t->size_chart_id, 'is_active' => $t->is_active,
                'fields' => GarmentFieldDefinition::query()->where('product_type_id', $t->id)->orderBy('sort_order')->orderBy('id')->get()->map(fn (GarmentFieldDefinition $f) => self::field($f))->all(),
            ])->all(),
            'option_sets' => GarmentOptionSet::query()->with(['options' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->orderBy('name')->get()->map(fn (GarmentOptionSet $s): array => [
                'id' => $s->id, 'code' => $s->code, 'name' => $s->name,
                'options' => $s->options->map(fn (GarmentOption $o): array => ['id' => $o->id, 'code' => $o->code, 'label' => $o->label, 'sort_order' => $o->sort_order, 'is_active' => $o->is_active])->all(),
            ])->all(),
            'size_charts' => SizeChart::query()->with(['sizes' => fn ($q) => $q->orderBy('sort_order')->orderBy('id')])->orderBy('name')->get()->map(fn (SizeChart $c): array => [
                'id' => $c->id, 'name' => $c->name,
                'sizes' => $c->sizes->map(fn (Size $s): array => ['id' => $s->id, 'code' => $s->code, 'label' => $s->label, 'sort_order' => $s->sort_order])->all(),
            ])->all(),
            'field_types' => GarmentFieldType::values(),
        ]);
    }

    public function storeField(Request $request): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate([
            'product_type_id' => ['required', 'integer', 'exists:product_types,id'],
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('garment_field_definitions')->where('product_type_id', $request->integer('product_type_id'))],
            ...self::fieldRules(partial: false),
        ], ['key.regex' => 'Use lower-case letters, numbers and underscores.']);
        $this->checkOptionSet($data);

        return $this->save(new GarmentFieldDefinition, $data, 'garment_setup.field_created', fn (Model $m) => self::field($m), 201, $request);
    }

    public function updateField(Request $request, GarmentFieldDefinition $field): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate(['key' => ['prohibited'], 'product_type_id' => ['prohibited'], ...self::fieldRules(partial: true)]);
        $this->checkOptionSet([...$field->only(['input_type', 'garment_option_set_id']), ...$data]);

        return $this->save($field, $data, 'garment_setup.field_updated', fn (Model $m) => self::field($m), 200, $request);
    }

    public function storeOptionSet(Request $request): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:garment_option_sets,code'],
            'name' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        return $this->save(new GarmentOptionSet, $data, 'garment_setup.option_set_created', fn (Model $m) => $m->only(['id', 'code', 'name']), 201, $request);
    }

    public function storeOption(Request $request, GarmentOptionSet $set): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9][a-z0-9_]*$/', Rule::unique('garment_options')->where('garment_option_set_id', $set->id)],
            'label' => ['required', 'string', 'min:1', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        return $this->save(new GarmentOption, [...$data, 'garment_option_set_id' => $set->id], 'garment_setup.option_created', fn (Model $m) => $m->only(['id', 'code', 'label', 'sort_order', 'is_active']), 201, $request);
    }

    public function updateOption(Request $request, GarmentOption $option): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate([
            'code' => ['prohibited'],
            'label' => ['sometimes', 'string', 'min:1', 'max:80'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $this->save($option, $data, 'garment_setup.option_updated', fn (Model $m) => $m->only(['id', 'code', 'label', 'sort_order', 'is_active']), 200, $request);
    }

    public function storeSizeChart(Request $request): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80', 'unique:size_charts,name']]);

        return $this->save(new SizeChart, $data, 'garment_setup.size_chart_created', fn (Model $m) => $m->only(['id', 'name']), 201, $request);
    }

    public function storeSize(Request $request, SizeChart $chart): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10', Rule::unique('sizes')->where('size_chart_id', $chart->id)],
            'label' => ['required', 'string', 'max:40'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);

        return $this->save(new Size, [...$data, 'size_chart_id' => $chart->id], 'garment_setup.size_created', fn (Model $m) => $m->only(['id', 'code', 'label', 'sort_order']), 201, $request);
    }

    public function updateSize(Request $request, Size $size): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate(['code' => ['prohibited'], 'label' => ['sometimes', 'string', 'max:40'], 'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999']]);

        return $this->save($size, $data, 'garment_setup.size_updated', fn (Model $m) => $m->only(['id', 'code', 'label', 'sort_order']), 200, $request);
    }

    public function setCategoryChart(Request $request, ProductType $category): JsonResponse
    {
        Gate::authorize('garment-setup.manage');
        $data = $request->validate(['size_chart_id' => ['required', 'integer', 'exists:size_charts,id']]);

        return $this->save($category, $data, 'garment_setup.category_chart_changed', fn (Model $m) => $m->only(['id', 'size_chart_id']), 200, $request);
    }

    /** @return array<string, list<mixed>> */
    private static function fieldRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'label' => [$required, 'string', 'min:1', 'max:80'],
            'input_type' => [$required, Rule::enum(GarmentFieldType::class)],
            'garment_option_set_id' => ['sometimes', 'nullable', 'integer', 'exists:garment_option_sets,id'],
            'is_required' => ['sometimes', 'boolean'],
            'affects_price' => ['sometimes', 'boolean'],
            'warn_on_duplicate' => ['sometimes', 'boolean'],
            'max_length' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:120'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function checkOptionSet(array $data): void
    {
        $type = $data['input_type'] instanceof GarmentFieldType ? $data['input_type']->value : ($data['input_type'] ?? null);
        if ($type === GarmentFieldType::Select->value && empty($data['garment_option_set_id'])) {
            throw new ApiException(422, 'validation_failed', 'Select fields need an option set.', ['garment_option_set_id' => ['Select fields need an option set.']]);
        }
    }

    /** @return array<string, mixed> */
    private static function field(Model $f): array
    {
        /** @var GarmentFieldDefinition $f */
        return [
            'id' => $f->id, 'product_type_id' => $f->product_type_id, 'key' => $f->key, 'label' => $f->label, 'input_type' => $f->input_type->value,
            'garment_option_set_id' => $f->garment_option_set_id, 'is_required' => $f->is_required, 'affects_price' => $f->affects_price,
            'warn_on_duplicate' => $f->warn_on_duplicate, 'max_length' => $f->max_length, 'sort_order' => $f->sort_order, 'is_active' => $f->is_active,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(Model): array<string, mixed>  $present
     */
    private function save(Model $model, array $data, string $action, callable $present, int $status, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        DB::transaction(function () use ($model, $data, $action, $actor): void {
            $before = $model->exists ? $model->only(array_keys($data)) : [];
            $model->forceFill($data)->save();
            $this->audit->record($action, $model, ['before' => $before, 'after' => $data], actor: $actor);
        });

        return ApiResponse::data($present($model->refresh()), $status);
    }
}
