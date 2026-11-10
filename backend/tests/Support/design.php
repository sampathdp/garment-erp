<?php

declare(strict_types=1);

use App\Modules\Design\Models\DesignTask;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Orders\Models\Order;
use App\Modules\Organizations\Models\Page;
use App\Modules\Organizations\Support\WorkflowSettings;
use App\Modules\Pricing\Models\Material;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\Size;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 04 fixtures on top of intakeWorld(): a designer configured as responsible designer, a
 * bulk designer, a fabric type, jersey sizes S/M/L and page A's price list:
 *
 *   jersey  short sleeve adult  LKR 1,000.00    long sleeve adult  LKR 1,200.00
 *           short sleeve kids   LKR   800.00    long sleeve kids   LKR   950.00
 */
function designWorld(TestCase $test): void
{
    intakeWorld($test);
    Storage::fake('private');

    $test->designer = $test->userWithRole(Roles::DESIGN_DESIGNER);
    $test->bulkDesigner = $test->userWithRole(Roles::BULK_DESIGNER);
    $test->admin = $test->userWithRole(Roles::SUPER_ADMIN);
    app(WorkflowSettings::class)->update($test->admin, [
        WorkflowSettings::DEFAULT_DESIGNER => $test->designer->id,
        WorkflowSettings::DEFAULT_BULK_DESIGNER => $test->bulkDesigner->id,
    ]);

    $test->fabric = Material::query()->create(['code' => 'wet_look', 'name' => 'Wet look', 'is_active' => true]);
    $chartId = ProductType::query()->whereKey($test->jersey->id)->value('size_chart_id');
    $test->sizes = collect(['S', 'M', 'L'])->mapWithKeys(fn (string $s, int $i) => [$s => Size::query()->create(['size_chart_id' => $chartId, 'code' => $s, 'label' => $s, 'sort_order' => $i])]);

    $test->priceList = priceList($test, $test->pageA, ['short|adult' => 100000, 'long|adult' => 120000, 'short|kids' => 80000, 'long|kids' => 95000]);
}

/** @param  array<string, int>  $prices  "sleeve|age" => unit price (minor) */
function priceList(TestCase $test, Page $page, array $prices, string $from = '2026-01-01', ?string $to = null, int $minQty = 1): PriceList
{
    $list = PriceList::query()->create(['page_id' => $page->id, 'name' => "{$page->code} prices {$from}", 'valid_from' => $from, 'valid_to' => $to, 'is_active' => true, 'created_by' => $test->admin->id]);
    foreach ($prices as $key => $price) {
        [$sleeve, $age] = explode('|', $key);
        $rule = PriceListItem::query()->create(['price_list_id' => $list->id, 'product_type_id' => $test->jersey->id, 'min_qty' => $minQty, 'unit_price_minor' => $price, 'label' => $key]);
        PriceListItemCondition::query()->create(['price_list_item_id' => $rule->id, 'field_key' => 'sleeve_type', 'option_code' => $sleeve]);
        PriceListItemCondition::query()->create(['price_list_item_id' => $rule->id, 'field_key' => 'age_group', 'option_code' => $age]);
    }

    return $list;
}

/** An Approved Order: 10 jerseys, LKR 10,000 estimate, 30% advance verified. */
function approvedOrder(TestCase $test, array $overrides = [], int $paidMinor = 300000): Order
{
    $order = createOrder($test, $overrides);
    verifyPayment($test, recordPayment($test, $order, $paidMinor));

    return $order->fresh();
}

function submitJobDetails(TestCase $test, Order $order, ?User $as = null): DesignTask
{
    $order->refresh();
    $test->signedInAs($as ?? $test->owner)->post("/api/v1/orders/{$order->id}/job-details", [
        'version' => $order->version, 'material_id' => $test->fabric->id, 'material_details' => '160 GSM',
        'design_instructions' => 'Blue and white, club logo on the chest', 'custom_notes' => 'Rush', 'overall_remarks' => 'VIP club',
    ], ['Accept' => 'application/json'])->assertOk();
    $order->refresh();
    $test->signedInAs($as ?? $test->owner)->postJson("/api/v1/orders/{$order->id}/job-details/submit", ['version' => $order->version])->assertOk();

    return DesignTask::query()->where('order_id', $order->id)->sole();
}

function pngFile(string $name = 'preview.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

function pdfFile(string $name = 'design.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
}

function cdrFile(string $name = 'design.cdr'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, 'RIFF'.pack('V', 100).'CDR9'.str_repeat("\0", 96));
}

function submitVersion(TestCase $test, DesignTask $task, array $extra = [])
{
    return $test->signedInAs($test->designer)->post("/api/v1/design-tasks/{$task->id}/versions", ['preview' => pngFile(), 'files' => [pdfFile()], 'notes' => 'First draft', ...$extra], ['Accept' => 'application/json']);
}

/** Version → approval → final link: the design task is completed. */
function completeDesign(TestCase $test, DesignTask $task): void
{
    submitVersion($test, $task)->assertOk();
    $test->signedInAs($test->owner)->postJson("/api/v1/design-tasks/{$task->id}/approve", ['channel' => 'whatsapp', 'comment' => 'Customer approved'])->assertOk();
    $test->signedInAs($test->designer)->post("/api/v1/design-tasks/{$task->id}/final", ['files' => [cdrFile()], 'external_url' => 'https://drive.google.com/file/d/abc123/view'], ['Accept' => 'application/json'])->assertOk();
}

/**
 * Rows for the jersey list. Default: 10 rows = 6 short adult + 4 long kids (numbers 1..10).
 *
 * @return list<array<string, mixed>>
 */
function jerseyRows(TestCase $test, int $shortAdult = 6, int $longKids = 4): array
{
    $rows = [];
    $n = 0;
    foreach ([[$shortAdult, 'short', 'adult', 'M'], [$longKids, 'long', 'kids', 'S']] as [$count, $sleeve, $age, $size]) {
        for ($i = 0; $i < $count; $i++) {
            $n++;
            $rows[] = ['size_id' => $test->sizes[$size]->id, 'quantity' => 1, 'player_name' => "Player {$n}", 'number' => (string) $n, 'values' => ['sleeve_type' => $sleeve, 'age_group' => $age]];
        }
    }

    return $rows;
}

/** @return array<string, mixed> the list payload */
function garmentList(TestCase $test, Order $order, ?User $as = null): array
{
    return $test->signedInAs($as ?? $test->owner)->getJson("/api/v1/orders/{$order->id}/garment-list")->assertOk()->json('data');
}

function fillList(TestCase $test, Order $order, ?array $rows = null): array
{
    $list = garmentList($test, $order);

    return $test->signedInAs($test->owner)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => $rows ?? jerseyRows($test)])->assertOk()->json('data');
}

function confirmList(TestCase $test, Order $order, bool $ack = true)
{
    $list = garmentList($test, $order);

    return $test->signedInAs($test->owner)->postJson("/api/v1/garment-lists/{$list['id']}/confirm", ['version' => $list['version'], 'acknowledge_warnings' => $ack]);
}

function listModel(Order $order): GarmentList
{
    return GarmentList::query()->whereHas('orderItem', fn ($q) => $q->where('order_id', $order->id))->sole();
}
