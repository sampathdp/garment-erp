<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\BulkProduction\Enums\ChecklistStage;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\Garments\Enums\GarmentFieldType;
use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentOption;
use App\Modules\Garments\Models\GarmentOptionSet;
use App\Modules\Orders\Models\OrderType;
use App\Modules\Organizations\Models\AppSetting;
use App\Modules\Organizations\Support\WorkflowSettings;
use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\SizeChart;
use Illuminate\Database\Seeder;

/**
 * Initial order types, product categories and name/number list fields. Idempotent and safe in
 * production (`php artisan db:seed --class=CatalogSeeder`): existing entries keep what
 * administrators changed.
 *
 * Deliberately NOT seeded (unknown until the client confirms): sizes, fabric types (materials),
 * bottom categories and prices. They are entered in the setup screens.
 * Seeded for bulk (Phase 05): bulk meter / patch meter fields and editable starter checklists.
 * Seeded for cutting (Phase 07): a starter cutting checklist and starter quality checks.
 */
class CatalogSeeder extends Seeder
{
    public const ORDER_TYPES = ['single' => 'Single Order', 'bulk' => 'Bulk Order'];

    public const PRODUCT_CATEGORIES = ['jersey' => 'Jersey', 'arm_cut' => 'Arm Cut', 'short' => 'Short', 'bottom' => 'Bottom', 't_shirt' => 'T-Shirt'];

    private const UPPER = ['jersey', 'arm_cut', 't_shirt'];

    private const LOWER = ['short', 'bottom'];

    public function run(): void
    {
        $order = 0;
        foreach (self::ORDER_TYPES as $code => $name) {
            OrderType::query()->firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'sort_order' => ++$order]);
        }

        $chart = SizeChart::query()->firstOrCreate(['name' => SizeChart::STANDARD]);
        foreach (self::PRODUCT_CATEGORIES as $code => $name) {
            ProductType::query()->withTrashed()->firstOrCreate(['code' => $code], ['name' => $name, 'size_chart_id' => $chart->id, 'is_active' => true]);
        }

        $sleeve = $this->optionSet('sleeve_type', 'Sleeve types', ['short' => 'Short sleeve', 'long' => 'Long sleeve', 'sleeveless' => 'Sleeveless']);
        $age = $this->optionSet('age_group', 'Kids / Adult', ['adult' => 'Adult', 'kids' => 'Kids']);
        $bottom = $this->optionSet('bottom_category', 'Bottom categories', [
            'plain' => 'Plain',
            'side_patches' => 'Side Patches',
            'side_color_line_piping' => 'Side Color Line Piping',
            'front_sublimation' => 'Front Only Sublimation Print',
            'full_sublimation' => 'Full Sublimation Print',
        ]);

        foreach (self::UPPER as $code) {
            $this->fields($code, [
                ['player_name', 'Player Name', GarmentFieldType::Text, null, false, false, false, 30],
                ['number', 'Player Number', GarmentFieldType::Number, null, false, false, true, 3],
                ['sleeve_type', 'Sleeve Type', GarmentFieldType::Select, $sleeve->id, true, true, false, null],
                ['age_group', 'Kids / Adult', GarmentFieldType::Select, $age->id, true, true, false, null],
            ]);
        }
        foreach (self::LOWER as $code) {
            $this->fields($code, [
                ['number', 'Player Number', GarmentFieldType::Number, null, false, false, true, 3],
                ['logo_required', 'Logo Required', GarmentFieldType::Boolean, null, false, false, false, null],
                ['bottom_category', 'Bottom Category', GarmentFieldType::Select, $bottom->id, false, true, false, null],
            ]);
        }

        $this->bulkSetup();
    }

    /**
     * Phase 05: bulk measurements and starter checklists. Bulk meter and patch meter are system
     * fields (always required). The collar meter is optional and only offered for upper-body
     * categories [ASSUMPTION: the client confirms which categories have collar measurements].
     * The checklist items are a starting point; administrators replace them in the Bulk setup screen.
     */
    private function bulkSetup(): void
    {
        foreach ([[BulkMeasurementField::BULK_METER, 'Bulk Meter', 10], [BulkMeasurementField::PATCH_METER, 'Patch Meter', 20]] as [$key, $label, $sort]) {
            $field = BulkMeasurementField::query()->whereNull('product_type_id')->where('key', $key)->first();
            if ($field === null) {
                $field = new BulkMeasurementField(['key' => $key, 'label' => $label, 'unit' => 'm', 'min_value' => '0.01', 'max_value' => '10000', 'decimals' => 2,
                    'is_required' => true, 'show_on_job_card' => true, 'sort_order' => $sort, 'is_active' => true]);
                $field->forceFill(['is_system' => true])->save();
            }
        }
        foreach (self::UPPER as $code) {
            $type = ProductType::query()->withTrashed()->where('code', $code)->first();
            if ($type !== null) {
                BulkMeasurementField::query()->firstOrCreate(['key' => 'collar_meter', 'product_type_id' => $type->id], [
                    'label' => 'Collar Meter', 'unit' => 'm', 'min_value' => '0', 'max_value' => '1000', 'decimals' => 2,
                    'is_required' => false, 'show_on_job_card' => true, 'sort_order' => 30, 'is_active' => true,
                ]);
            }
        }

        $templates = [
            [ChecklistStage::BulkPreparation, 'Internal bulk checklist', [
                'Pattern — verify cutting pattern & template',
                'Adult / Kids — verify adult vs kids cut & proportions',
                'Material — verify fabric type & GSM specification',
                'Width — verify fabric roll width',
                'Logo — verify logo placement, size & print resolution',
                'Labels — verify brand labels, neck tags & care labels',
                'Name/No — cross-check names & numbers against confirmed roster',
                'Quantity — verify total piece count matches order',
                'Collars — verify collar design, cut & ribbing specifications',
                'Patch — verify sleeve patches, arm cuts & tournament badges',
            ]],
            [ChecklistStage::BulkExternal, 'External bulk checklist', [
                'External producer received the approved design and confirmed list',
                'Names, numbers and sizes checked on the returned bulk',
                'Quantity checked against the order',
                'Bulk meter and patch meter recorded',
            ]],
            // Phase 07 starter templates (client to confirm the final items).
            [ChecklistStage::Cutting, 'Cutting checklist', [
                'Printed panels counted against the confirmed list',
                'Pieces cut for every size in the list',
                'Sleeves, collars and other parts cut and matched',
                'Cut pieces bundled and labelled by size and name/number',
            ]],
            [ChecklistStage::QualityControl, 'Cutting quality checks', [
                'Cut edges clean, no fraying',
                'Print alignment correct on the cut panels',
                'Panel sizes match the size chart',
                'Names and numbers correct on the cut panels',
            ]],
        ];
        foreach ($templates as [$stage, $name, $items]) {
            if (ChecklistTemplate::query()->where('stage', $stage->value)->exists()) {
                continue; // administrators own the templates once any exists
            }
            $template = ChecklistTemplate::query()->create(['stage' => $stage, 'product_type_id' => null, 'name' => $name, 'version_no' => 1, 'is_active' => true]);
            foreach ($items as $i => $label) {
                ChecklistTemplateItem::query()->create(['checklist_template_id' => $template->id, 'label' => $label, 'is_required' => true, 'sort_order' => ($i + 1) * 10]);
            }
        }
    }

    /** @param  array<string, string>  $options */
    private function optionSet(string $code, string $name, array $options): GarmentOptionSet
    {
        $set = GarmentOptionSet::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        $sort = 0;
        foreach ($options as $optionCode => $label) {
            GarmentOption::query()->firstOrCreate(['garment_option_set_id' => $set->id, 'code' => $optionCode], ['label' => $label, 'sort_order' => ++$sort, 'is_active' => true]);
        }

        return $set;
    }

    /** @param  list<array{0: string, 1: string, 2: GarmentFieldType, 3: int|null, 4: bool, 5: bool, 6: bool, 7: int|null}>  $fields */
    private function fields(string $categoryCode, array $fields): void
    {
        $type = ProductType::query()->withTrashed()->where('code', $categoryCode)->first();
        if ($type === null) {
            return;
        }
        foreach ($fields as $i => [$key, $label, $inputType, $setId, $required, $affectsPrice, $warnDuplicate, $maxLength]) {
            GarmentFieldDefinition::query()->firstOrCreate(['product_type_id' => $type->id, 'key' => $key], [
                'label' => $label, 'input_type' => $inputType, 'garment_option_set_id' => $setId, 'is_required' => $required,
                'affects_price' => $affectsPrice, 'warn_on_duplicate' => $warnDuplicate, 'max_length' => $maxLength, 'sort_order' => ($i + 1) * 10, 'is_active' => true,
            ]);
        }
    }
}
