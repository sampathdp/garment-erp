<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Http\Controllers;

use App\Modules\BulkProduction\Actions\ManageBulkSetup;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\SupplierService;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Identity\Models\User;
use App\Modules\Pricing\Models\ProductType;
use App\Support\Http\ApiResponse;
use App\Support\Validation\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Bulk configuration: measurement fields, checklist templates, external producers (bulk.configure). */
final class BulkSetupController
{
    private const STAGES = [ChecklistStage::BulkPreparation, ChecklistStage::BulkExternal];

    public function index(): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');

        return ApiResponse::data([
            'product_types' => ProductType::query()->orderBy('name')->get(['id', 'name'])->map(fn (ProductType $p) => ['id' => $p->id, 'name' => $p->name])->all(),
            'stages' => array_map(fn (ChecklistStage $s) => ['value' => $s->value, 'label' => $s === ChecklistStage::BulkPreparation ? 'Internal bulk' : 'External bulk'], self::STAGES),
            'service_types' => SupplierService::values(),
            'measurement_fields' => BulkMeasurementField::query()->with('productType:id,name')->orderBy('sort_order')->orderBy('id')->get()->map(fn (BulkMeasurementField $f): array => [
                'id' => $f->id, 'key' => $f->key, 'label' => $f->label, 'unit' => $f->unit, 'product_type' => $f->productType?->name, 'product_type_id' => $f->product_type_id,
                'min_value' => $f->min_value, 'max_value' => $f->max_value, 'decimals' => $f->decimals, 'is_required' => $f->is_required, 'is_system' => $f->is_system,
                'show_on_job_card' => $f->show_on_job_card, 'sort_order' => $f->sort_order, 'is_active' => $f->is_active,
            ])->all(),
            'checklist_templates' => ChecklistTemplate::query()->whereIn('stage', array_map(fn ($s) => $s->value, self::STAGES))->with(['items', 'productType:id,name'])
                ->orderBy('stage')->orderByDesc('is_active')->orderByDesc('version_no')->get()->map(fn (ChecklistTemplate $t): array => [
                    'id' => $t->id, 'stage' => $t->stage->value, 'name' => $t->name, 'version_no' => $t->version_no, 'is_active' => $t->is_active,
                    'product_type' => $t->productType?->name, 'product_type_id' => $t->getAttribute('product_type_id'),
                    'items' => $t->items->sortBy('sort_order')->values()->map(fn (ChecklistTemplateItem $i) => ['label' => $i->label, 'is_required' => $i->is_required])->all(),
                ])->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get()->map(fn (Supplier $s): array => [
                'id' => $s->id, 'name' => $s->name, 'phone' => $s->getAttribute('phone_e164'), 'email' => $s->getAttribute('email'), 'address' => $s->getAttribute('address'),
                'service_type' => $s->service_type->value, 'is_active' => $s->is_active,
            ])->all(),
        ]);
    }

    public function storeField(Request $request, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
            'label' => ['required', 'string', 'max:80'],
            'unit' => ['required', 'string', 'max:12'],
            'product_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_types', 'id')],
            ...$this->fieldRules(),
        ]);
        $field = $setup->createField($this->actor($request), $data);

        return ApiResponse::data(['id' => $field->id], 201);
    }

    public function updateField(Request $request, BulkMeasurementField $field, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:80'],
            'unit' => ['sometimes', 'string', 'max:12'],
            ...$this->fieldRules(),
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $setup->updateField($this->actor($request), $field, $data);

        return ApiResponse::data(['id' => $field->id]);
    }

    public function storeTemplate(Request $request, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_map(fn ($s) => $s->value, self::STAGES))],
            'product_type_id' => ['sometimes', 'nullable', 'integer', Rule::exists('product_types', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1', 'max:60'],
            'items.*.label' => ['required', 'string', 'max:255'],
            'items.*.is_required' => ['sometimes', 'boolean'],
        ]);
        $template = $setup->createTemplate($this->actor($request), ChecklistStage::from($data['stage']), isset($data['product_type_id']) ? (int) $data['product_type_id'] : null, $data['name'], array_values($data['items']));

        return ApiResponse::data(['id' => $template->id, 'version_no' => $template->version_no], 201);
    }

    public function updateTemplate(Request $request, ChecklistTemplate $template, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        abort_unless(in_array($template->stage, self::STAGES, true), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $setup->setTemplateActive($this->actor($request), $template, (bool) $data['is_active']);

        return ApiResponse::data(['id' => $template->id]);
    }

    public function storeSupplier(Request $request, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        $supplier = $setup->saveSupplier($this->actor($request), null, $this->supplierData($request, true));

        return ApiResponse::data(['id' => $supplier->id], 201);
    }

    public function updateSupplier(Request $request, Supplier $supplier, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('bulk-setup.manage');
        $setup->saveSupplier($this->actor($request), $supplier, $this->supplierData($request, false));

        return ApiResponse::data(['id' => $supplier->id]);
    }

    /** @return array<string, mixed> */
    private function supplierData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20', PhoneNumber::rule()],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'service_type' => [$required, Rule::enum(SupplierService::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('phone', $data)) {
            $data['phone_e164'] = PhoneNumber::normalize($data['phone']);
            unset($data['phone']);
        }

        return $data;
    }

    /** @return array<string, list<mixed>> */
    private function fieldRules(): array
    {
        return [
            'min_value' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'max_value' => ['sometimes', 'numeric', 'min:0', 'max:999999999'],
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'is_required' => ['sometimes', 'boolean'],
            'show_on_job_card' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
