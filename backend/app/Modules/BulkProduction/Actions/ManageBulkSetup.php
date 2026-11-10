<?php

declare(strict_types=1);

namespace App\Modules\BulkProduction\Actions;

use App\Modules\Audit\AuditLogger;
use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Enums\SupplierService;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Identity\Models\User;
use App\Support\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Bulk configuration (bulk.configure): measurement fields, checklist templates and external
 * producers (suppliers). Nothing is deleted: fields, templates and suppliers are deactivated.
 *
 * Checklist templates are versioned: saving a template for a stage + category creates a new
 * version and deactivates the earlier ones. Existing jobs keep the item snapshot they started with.
 */
final class ManageBulkSetup
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $data */
    public function createField(User $actor, array $data): BulkMeasurementField
    {
        return DB::transaction(function () use ($actor, $data): BulkMeasurementField {
            $exists = BulkMeasurementField::query()->where('key', $data['key'])
                ->where(fn ($q) => isset($data['product_type_id']) ? $q->where('product_type_id', $data['product_type_id']) : $q->whereNull('product_type_id'))->exists();
            if ($exists) {
                throw new ApiException(422, 'validation_failed', 'A field with this key already exists for the category.', ['key' => ['A field with this key already exists for the category.']]);
            }
            $this->assertRange($data);
            $field = BulkMeasurementField::query()->create($data);
            $this->audit->record('bulk.setup.field_created', $field, ['after' => $data], actor: $actor);

            return $field;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function updateField(User $actor, BulkMeasurementField $field, array $data): BulkMeasurementField
    {
        return DB::transaction(function () use ($actor, $field, $data): BulkMeasurementField {
            $field = BulkMeasurementField::query()->lockForUpdate()->findOrFail($field->id);
            if ($field->is_system && ((array_key_exists('is_active', $data) && ! $data['is_active']) || (array_key_exists('is_required', $data) && ! $data['is_required']))) {
                throw ApiException::conflict('system_field', "{$field->label} is always required and cannot be deactivated.");
            }
            $this->assertRange([...$field->only(['min_value', 'max_value']), ...$data]);
            $before = $field->only(array_keys($data));
            $field->fill($data)->save();
            $this->audit->record('bulk.setup.field_updated', $field, ['before' => $before, 'after' => $data], actor: $actor);

            return $field;
        });
    }

    /** @param  list<array{label: string, is_required?: bool}>  $items */
    public function createTemplate(User $actor, ChecklistStage $stage, ?int $productTypeId, string $name, array $items): ChecklistTemplate
    {
        return DB::transaction(function () use ($actor, $stage, $productTypeId, $name, $items): ChecklistTemplate {
            $scope = fn ($q) => $q->where('stage', $stage->value)->where(fn ($w) => $productTypeId === null ? $w->whereNull('product_type_id') : $w->where('product_type_id', $productTypeId));
            $previous = ChecklistTemplate::query()->where($scope)->lockForUpdate()->get();

            $template = ChecklistTemplate::query()->create([
                'stage' => $stage, 'product_type_id' => $productTypeId, 'name' => $name,
                'version_no' => (int) $previous->where('name', $name)->max('version_no') + 1, 'is_active' => true,
            ]);
            foreach ($items as $i => $item) {
                ChecklistTemplateItem::query()->create(['checklist_template_id' => $template->id, 'label' => $item['label'], 'is_required' => (bool) ($item['is_required'] ?? true), 'sort_order' => ($i + 1) * 10]);
            }
            ChecklistTemplate::query()->where($scope)->whereKeyNot($template->id)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);

            $this->audit->record('bulk.setup.template_created', $template, ['after' => ['stage' => $stage->value, 'product_type_id' => $productTypeId, 'name' => $name, 'version_no' => $template->version_no, 'items' => $items]], actor: $actor);

            return $template;
        });
    }

    public function setTemplateActive(User $actor, ChecklistTemplate $template, bool $active): ChecklistTemplate
    {
        return DB::transaction(function () use ($actor, $template, $active): ChecklistTemplate {
            $template = ChecklistTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($active) {
                ChecklistTemplate::query()->where('stage', $template->stage->value)->whereKeyNot($template->id)
                    ->where(fn ($w) => $template->product_type_id === null ? $w->whereNull('product_type_id') : $w->where('product_type_id', $template->product_type_id))
                    ->update(['is_active' => false, 'updated_at' => now()]);
            }
            $template->forceFill(['is_active' => $active])->save();
            $this->audit->record('bulk.setup.template_updated', $template, ['after' => ['is_active' => $active]], actor: $actor);

            return $template;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function saveSupplier(User $actor, ?Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($actor, $supplier, $data): Supplier {
            if (isset($data['service_type']) && is_string($data['service_type'])) {
                $data['service_type'] = SupplierService::from($data['service_type']);
            }
            $before = $supplier?->only(array_keys($data));
            $supplier ??= new Supplier(['is_active' => true]);
            $supplier->fill($data)->save();
            $this->audit->record($before === null ? 'bulk.setup.supplier_created' : 'bulk.setup.supplier_updated', $supplier, ['before' => $before, 'after' => $data], actor: $actor);

            return $supplier;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function assertRange(array $data): void
    {
        if (isset($data['min_value'], $data['max_value']) && (float) $data['min_value'] > (float) $data['max_value']) {
            throw new ApiException(422, 'validation_failed', 'The minimum must not be greater than the maximum.', ['min_value' => ['The minimum must not be greater than the maximum.']]);
        }
    }
}
