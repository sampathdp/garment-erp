<?php

declare(strict_types=1);

namespace App\Modules\Cutting\Http\Controllers;

use App\Modules\BulkProduction\Actions\ManageBulkSetup;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\Cutting\Support\CuttingChecklists;
use App\Modules\Identity\Models\User;
use App\Modules\Pricing\Models\ProductType;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Cutting configuration (cutting.configure): the versioned cutting checklist and quality check
 * templates (stages `cutting` and `quality_control`). Templates are never deleted; saving one
 * creates a new version, and existing jobs keep the item snapshot they started with.
 */
final class CuttingSetupController
{
    public function index(): JsonResponse
    {
        Gate::authorize('cutting-setup.manage');
        $stages = array_map(fn (ChecklistStage $s) => $s->value, CuttingChecklists::STAGES);

        return ApiResponse::data([
            'product_types' => ProductType::query()->orderBy('name')->get(['id', 'name'])->map(fn (ProductType $p) => ['id' => $p->id, 'name' => $p->name])->all(),
            'stages' => [['value' => ChecklistStage::Cutting->value, 'label' => 'Cutting checklist'], ['value' => ChecklistStage::QualityControl->value, 'label' => 'Quality checks']],
            'checklist_templates' => ChecklistTemplate::query()->whereIn('stage', $stages)->with(['items', 'productType:id,name'])
                ->orderBy('stage')->orderByDesc('is_active')->orderByDesc('version_no')->get()->map(fn (ChecklistTemplate $t): array => [
                    'id' => $t->id, 'stage' => $t->stage->value, 'name' => $t->name, 'version_no' => $t->version_no, 'is_active' => $t->is_active,
                    'product_type' => $t->productType?->name, 'product_type_id' => $t->getAttribute('product_type_id'),
                    'items' => $t->items->sortBy('sort_order')->values()->map(fn (ChecklistTemplateItem $i) => ['label' => $i->label, 'is_required' => $i->is_required])->all(),
                ])->all(),
        ]);
    }

    public function storeTemplate(Request $request, ManageBulkSetup $setup): JsonResponse
    {
        Gate::authorize('cutting-setup.manage');
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_map(fn ($s) => $s->value, CuttingChecklists::STAGES))],
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
        Gate::authorize('cutting-setup.manage');
        abort_unless(in_array($template->stage, CuttingChecklists::STAGES, true), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $setup->setTemplateActive($this->actor($request), $template, (bool) $data['is_active']);

        return ApiResponse::data(['id' => $template->id]);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
