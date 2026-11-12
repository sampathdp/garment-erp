<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Garments\Models\GarmentOption;
use App\Modules\Garments\Models\GarmentOptionSet;
use App\Modules\Organizations\Models\Page;
use App\Modules\Pricing\Models\Material;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Modules\Pricing\Models\ProductType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PriceListSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure Garment Option Set 3 (bottom_category) has options
        $set = GarmentOptionSet::query()->firstOrCreate(['code' => 'bottom_category'], ['name' => 'Bottom categories']);

        $bottomOptions = [
            'plain' => ['label' => 'Plain', 'sort_order' => 1],
            'side_patches' => ['label' => 'Side Patches', 'sort_order' => 2],
            'side_color_line_piping' => ['label' => 'Side Color Line Piping', 'sort_order' => 3],
            'front_sublimation' => ['label' => 'Front Only Sublimation Print', 'sort_order' => 4],
            'full_sublimation' => ['label' => 'Full Sublimation Print', 'sort_order' => 5],
        ];

        foreach ($bottomOptions as $code => $opt) {
            GarmentOption::query()->firstOrCreate(
                ['garment_option_set_id' => $set->id, 'code' => $code],
                ['label' => $opt['label'], 'sort_order' => $opt['sort_order'], 'is_active' => true]
            );
        }

        // 2. Fetch Product Types
        $jerseyPt = ProductType::query()->where('code', 'jersey')->first();
        $armCutPt = ProductType::query()->where('code', 'arm_cut')->first();
        $tShirtPt = ProductType::query()->where('code', 't_shirt')->first();
        $shortPt = ProductType::query()->where('code', 'short')->first();
        $bottomPt = ProductType::query()->where('code', 'bottom')->first();

        // 3. Setup Product Options (DTF Logo & Embroider Logo)
        $productTypes = ProductType::all();
        $addonOptions = [
            'dtf_logo' => ['name' => 'DTF Logo or Number', 'surcharge_minor' => 5000],
            'embroider_logo' => ['name' => 'Embroider Logo', 'surcharge_minor' => 15000],
        ];

        $createdOptions = [];
        foreach ($productTypes as $pt) {
            foreach ($addonOptions as $code => $ao) {
                $opt = DB::table('product_options')->where('product_type_id', $pt->id)->where('code', $code)->first();
                if (! $opt) {
                    $id = DB::table('product_options')->insertGetId([
                        'product_type_id' => $pt->id,
                        'code' => $code,
                        'name' => $ao['name'],
                        'is_active' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $id = $opt->id;
                }
                $createdOptions[$pt->id][$code] = [
                    'id' => $id,
                    'surcharge_minor' => $ao['surcharge_minor'],
                ];
            }
        }

        // 4. Materials
        $materials = [
            'standard' => ['id' => null, 'name' => 'Standard Fabric', 'surcharge_minor' => 0],
            'honeycomb' => ['id' => Material::query()->where('code', 'honeycomb')->value('id'), 'name' => 'Honeycomb (+100)', 'surcharge_minor' => 10000],
            'birds_eye' => ['id' => Material::query()->where('code', 'birds_eye')->value('id'), 'name' => "Bird's Eye (+200)", 'surcharge_minor' => 20000],
            'closehole' => ['id' => Material::query()->where('code', 'closehole')->value('id'), 'name' => 'Closehole (+200)', 'surcharge_minor' => 20000],
            'wetlook_200gsm' => ['id' => Material::query()->where('code', 'wetlook_200gsm')->value('id'), 'name' => '200GSM Wetlook (+200)', 'surcharge_minor' => 20000],
            'baby_pk_crocodile' => ['id' => Material::query()->where('code', 'baby_pk_crocodile')->value('id'), 'name' => 'Baby PK Crocodile (+200)', 'surcharge_minor' => 20000],
        ];

        $tiers = [
            ['min' => 1, 'max' => 9, 'idx' => 0, 'label' => '1-9 pcs'],
            ['min' => 10, 'max' => 19, 'idx' => 0, 'label' => '10-19 pcs'],
            ['min' => 20, 'max' => 49, 'idx' => 1, 'label' => '20-49 pcs'],
            ['min' => 50, 'max' => 99, 'idx' => 2, 'label' => '50-99 pcs'],
            ['min' => 100, 'max' => 149, 'idx' => 3, 'label' => '100-149 pcs'],
            ['min' => 150, 'max' => null, 'idx' => 4, 'label' => '150+ pcs'],
        ];

        $bottomBasePrices = [
            'plain' => [1050, 1025, 1000, 975, 950],
            'side_patches' => [1100, 1075, 1050, 1025, 1000],
            'side_color_line_piping' => [1150, 1125, 1100, 1075, 1050],
            'front_sublimation' => [1300, 1275, 1250, 1225, 1200],
            'full_sublimation' => [1550, 1525, 1500, 1475, 1450],
        ];

        $shortBasePrices = [
            'plain' => [800, 775, 750, 725, 700],
            'side_color_line_piping' => [850, 825, 800, 775, 750],
            'side_patches' => [850, 825, 800, 775, 750],
            'front_sublimation' => [900, 875, 850, 825, 800],
            'full_sublimation' => [1000, 975, 950, 925, 900],
        ];

        $pages = Page::query()->where('is_active', true)->get();

        foreach ($pages as $page) {
            $priceList = PriceList::query()->where('page_id', $page->id)->where('is_active', true)->first();
            if (! $priceList) {
                $priceList = PriceList::query()->create([
                    'page_id' => $page->id,
                    'name' => 'Price List 2026',
                    'valid_from' => CarbonImmutable::now()->startOfYear()->toDateString(),
                    'is_active' => true,
                ]);
            }

            // Option surcharges
            foreach ($createdOptions as $ptId => $opts) {
                foreach ($opts as $code => $info) {
                    DB::table('price_list_option_prices')->updateOrInsert(
                        ['price_list_id' => $priceList->id, 'product_option_id' => $info['id']],
                        ['unit_surcharge_minor' => $info['surcharge_minor'], 'updated_at' => now(), 'created_at' => now()]
                    );
                }
            }

            // Ensure Bottoms
            if ($bottomPt) {
                $hasBottom = PriceListItem::query()->where('price_list_id', $priceList->id)->where('product_type_id', $bottomPt->id)->exists();
                if (! $hasBottom) {
                    foreach ($bottomBasePrices as $catCode => $tierPrices) {
                        $catLabel = $bottomOptions[$catCode]['label'];
                        foreach ($materials as $matKey => $mat) {
                            foreach ($tiers as $tier) {
                                $baseLkr = $tierPrices[$tier['idx']];
                                $priceMinor = ($baseLkr * 100) + $mat['surcharge_minor'];
                                $label = "Bottom {$catLabel} - {$tier['label']} ({$mat['name']})";

                                $itemId = DB::table('price_list_items')->insertGetId([
                                    'price_list_id' => $priceList->id,
                                    'product_type_id' => $bottomPt->id,
                                    'material_id' => $mat['id'],
                                    'branch_id' => null,
                                    'label' => $label,
                                    'min_qty' => $tier['min'],
                                    'max_qty' => $tier['max'],
                                    'unit_price_minor' => $priceMinor,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                DB::table('price_list_item_conditions')->insert([
                                    'price_list_item_id' => $itemId,
                                    'field_key' => 'bottom_category',
                                    'option_code' => $catCode,
                                ]);
                            }
                        }
                    }

                    // Fallback
                    foreach ($materials as $matKey => $mat) {
                        foreach ($tiers as $tier) {
                            $baseLkr = $bottomBasePrices['plain'][$tier['idx']];
                            $priceMinor = ($baseLkr * 100) + $mat['surcharge_minor'];
                            $label = "Bottom - {$tier['label']} ({$mat['name']})";

                            DB::table('price_list_items')->insert([
                                'price_list_id' => $priceList->id,
                                'product_type_id' => $bottomPt->id,
                                'material_id' => $mat['id'],
                                'branch_id' => null,
                                'label' => $label,
                                'min_qty' => $tier['min'],
                                'max_qty' => $tier['max'],
                                'unit_price_minor' => $priceMinor,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }

            // Ensure Shorts
            if ($shortPt) {
                $hasShort = PriceListItem::query()->where('price_list_id', $priceList->id)->where('product_type_id', $shortPt->id)->exists();
                if (! $hasShort) {
                    foreach ($shortBasePrices as $catCode => $tierPrices) {
                        $catLabel = $bottomOptions[$catCode]['label'];
                        foreach ($materials as $matKey => $mat) {
                            foreach ($tiers as $tier) {
                                $baseLkr = $tierPrices[$tier['idx']];
                                $priceMinor = ($baseLkr * 100) + $mat['surcharge_minor'];
                                $label = "Short {$catLabel} - {$tier['label']} ({$mat['name']})";

                                $itemId = DB::table('price_list_items')->insertGetId([
                                    'price_list_id' => $priceList->id,
                                    'product_type_id' => $shortPt->id,
                                    'material_id' => $mat['id'],
                                    'branch_id' => null,
                                    'label' => $label,
                                    'min_qty' => $tier['min'],
                                    'max_qty' => $tier['max'],
                                    'unit_price_minor' => $priceMinor,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                DB::table('price_list_item_conditions')->insert([
                                    'price_list_item_id' => $itemId,
                                    'field_key' => 'bottom_category',
                                    'option_code' => $catCode,
                                ]);
                            }
                        }
                    }

                    // Fallback
                    foreach ($materials as $matKey => $mat) {
                        foreach ($tiers as $tier) {
                            $baseLkr = $shortBasePrices['plain'][$tier['idx']];
                            $priceMinor = ($baseLkr * 100) + $mat['surcharge_minor'];
                            $label = "Short - {$tier['label']} ({$mat['name']})";

                            DB::table('price_list_items')->insert([
                                'price_list_id' => $priceList->id,
                                'product_type_id' => $shortPt->id,
                                'material_id' => $mat['id'],
                                'branch_id' => null,
                                'label' => $label,
                                'min_qty' => $tier['min'],
                                'max_qty' => $tier['max'],
                                'unit_price_minor' => $priceMinor,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            }
        }
    }
}

