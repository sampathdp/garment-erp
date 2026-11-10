<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Permissions;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    designWorld($this);
    $this->order = approvedOrder($this);
});

it('prices each attribute group from the page price list and issues an invoice snapshot', function () {
    fillList($this, $this->order); // 6 short adult (1,000) + 4 long kids (950)
    $invoiceId = confirmList($this, $this->order)->assertOk()->json('data.invoice_id');

    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")->assertOk()
        ->assertJsonPath('data.invoice_no', $this->pageA->invoice_prefix.'-2026-000001')
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.lines.0.quantity', 6)->assertJsonPath('data.lines.0.unit_price_minor', 100000)
        ->assertJsonPath('data.lines.1.quantity', 4)->assertJsonPath('data.lines.1.unit_price_minor', 95000)
        ->assertJsonPath('data.subtotal_minor', 980000)
        ->assertJsonPath('data.tax_minor', 0)
        ->assertJsonPath('data.total_minor', 980000)
        ->assertJsonPath('data.payments.advance_required_minor', 294000)  // ceil(980,000 × 30%)
        ->assertJsonPath('data.payments.verified_paid_minor', 300000)
        ->assertJsonPath('data.payments.balance_minor', 680000)
        ->assertJsonPath('data.pricing_date', '2026-11-09');

    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$this->order->id}")->assertJsonPath('data.pricing.total_minor', 980000);
});

it('prices another page with its own price list and its own invoice numbering', function () {
    priceList($this, $this->pageB, ['short|adult' => 150000, 'long|adult' => 150000, 'short|kids' => 150000, 'long|kids' => 150000]);
    $orderB = createOrder($this, ['page_id' => $this->pageB->id], $this->ownerB);
    verifyPayment($this, recordPayment($this, $orderB, 300000, $this->ownerB), $this->secondB);

    $list = garmentList($this, $orderB, $this->ownerB);
    $this->signedInAs($this->ownerB)->postJson("/api/v1/garment-lists/{$list['id']}/rows", ['version' => $list['version'], 'rows' => jerseyRows($this)])->assertOk();
    $list = garmentList($this, $orderB, $this->ownerB);
    $invoiceId = $this->signedInAs($this->ownerB)->postJson("/api/v1/garment-lists/{$list['id']}/confirm", ['version' => $list['version'], 'acknowledge_warnings' => true])->assertOk()->json('data.invoice_id');

    $this->signedInAs($this->ownerB)->getJson("/api/v1/invoices/{$invoiceId}")->assertOk()
        ->assertJsonPath('data.invoice_no', $this->pageB->invoice_prefix.'-2026-000001')
        ->assertJsonPath('data.total_minor', 1500000);
    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")->assertNotFound();
});

it('applies page, material, branch and quantity-tier rules, most specific first', function () {
    $tierRule = PriceListItem::query()->create(['price_list_id' => $this->priceList->id, 'product_type_id' => $this->jersey->id, 'min_qty' => 10, 'unit_price_minor' => 90000, 'label' => 'short adult 10+']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $tierRule->id, 'field_key' => 'sleeve_type', 'option_code' => 'short']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $tierRule->id, 'field_key' => 'age_group', 'option_code' => 'adult']);
    $materialRule = PriceListItem::query()->create(['price_list_id' => $this->priceList->id, 'product_type_id' => $this->jersey->id, 'material_id' => $this->fabric->id, 'min_qty' => 1, 'unit_price_minor' => 99000, 'label' => 'long kids wet look']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $materialRule->id, 'field_key' => 'sleeve_type', 'option_code' => 'long']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $materialRule->id, 'field_key' => 'age_group', 'option_code' => 'kids']);

    submitJobDetails($this, $this->order); // sets the fabric (material) to wet look
    fillList($this, $this->order);
    $preview = garmentList($this, $this->order)['pricing_preview'];

    // 10 garments in total → the 10+ tier; and the material-specific kids price.
    expect(collect($preview['lines'])->pluck('unit_price_minor')->all())->toBe([90000, 99000]);

    // Page B's list does not apply to page A.
    priceList($this, $this->pageB, ['short|adult' => 1, 'long|adult' => 1, 'short|kids' => 1, 'long|kids' => 1]);
    expect(collect(garmentList($this, $this->order)['pricing_preview']['lines'])->pluck('unit_price_minor')->all())->toBe([90000, 99000]);
});

it('refuses to confirm when a garment group has no price', function () {
    PriceListItem::query()->where('label', 'long|kids')->delete();
    fillList($this, $this->order);

    confirmList($this, $this->order)->assertStatus(409)->assertJsonPath('code', 'pricing_missing')
        ->assertJsonFragment(['No price for Jersey — Long sleeve, Kids.']);
    expect(Invoice::query()->count())->toBe(0)
        ->and(garmentList($this, $this->order)['current_version']['status'])->toBe('draft'); // nothing locked
});

it('never changes an issued invoice when prices change, and freezes the used price list', function () {
    fillList($this, $this->order);
    $invoiceId = confirmList($this, $this->order)->assertOk()->json('data.invoice_id');
    $rule = PriceListItem::query()->where('label', 'short|adult')->sole();

    // The used list is frozen.
    $this->signedInAs($this->admin)->patchJson("/api/v1/price-lists/{$this->priceList->id}/rules/{$rule->id}", [
        'product_type_id' => $this->jersey->id, 'min_qty' => 1, 'unit_price_minor' => 1,
        'conditions' => [['field_key' => 'sleeve_type', 'option_code' => 'short'], ['field_key' => 'age_group', 'option_code' => 'adult']],
    ])->assertStatus(409)->assertJsonPath('code', 'price_list_in_use');

    // A price change is a new list from a later date; the old one is closed the day before.
    $newList = $this->signedInAs($this->admin)->postJson('/api/v1/price-lists', ['page_id' => $this->pageA->id, 'name' => '2027 prices', 'valid_from' => '2027-01-01', 'copy_from_id' => $this->priceList->id])
        ->assertCreated()->assertJsonPath('data.rules_count', 4)->json('data.id');
    $copied = PriceListItem::query()->where('price_list_id', $newList)->where('label', 'short|adult')->sole();
    $this->signedInAs($this->admin)->patchJson("/api/v1/price-lists/{$newList}/rules/{$copied->id}", [
        'product_type_id' => $this->jersey->id, 'min_qty' => 1, 'unit_price_minor' => 500000, 'label' => 'short|adult',
        'conditions' => [['field_key' => 'sleeve_type', 'option_code' => 'short'], ['field_key' => 'age_group', 'option_code' => 'adult']],
    ])->assertOk();
    expect($this->priceList->fresh()->valid_to->toDateString())->toBe('2026-12-31');

    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")->assertJsonPath('data.lines.0.unit_price_minor', 100000)->assertJsonPath('data.total_minor', 980000);
    expect(fn () => InvoiceItem::query()->first()->update(['unit_price_minor' => 1]))->toThrow(LogicException::class);

    // Overlapping lists are rejected.
    $this->signedInAs($this->admin)->postJson('/api/v1/price-lists', ['page_id' => $this->pageA->id, 'name' => 'Overlap', 'valid_from' => '2026-06-01', 'valid_to' => '2026-07-01'])
        ->assertStatus(422)->assertJsonValidationErrors('valid_from');
});

it('gives discounts only to authorised users and applies tax only when configured', function () {
    $noDiscount = $this->userWith([Permissions::ORDERS_CREATE, Permissions::ORDERS_VIEW, Permissions::ORDERS_SUBMIT, Permissions::SCOPE_ALL_BRANCHES], [], [$this->pageA]);
    $this->signedInAs($noDiscount)->postJson('/api/v1/orders', orderPayload($this, ['discount_minor' => 10000]))->assertStatus(422)->assertJsonValidationErrors('discount_minor');

    config(['invoices.tax_percent' => 18]);
    $order = approvedOrder($this, ['discount_minor' => 80000, 'customer' => ['name' => 'Discounted', 'phone' => '0779990000', 'whatsapp' => '0779990000']]);
    fillList($this, $order);
    $invoiceId = confirmList($this, $order)->assertOk()->json('data.invoice_id');

    // (980,000 − 80,000) × 18% = 162,000 tax
    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")
        ->assertJsonPath('data.discount_minor', 80000)->assertJsonPath('data.tax_percent', 18)
        ->assertJsonPath('data.tax_minor', 162000)->assertJsonPath('data.total_minor', 1062000);
});

it('recalculates the advance from the invoice and blocks production when it falls below 30%', function () {
    Notification::fake();
    // Paid exactly 30% of the 10,000 estimate; the invoice comes to 12,000 (all long adult).
    $rows = array_map(fn (array $r) => [...$r, 'values' => ['sleeve_type' => 'long', 'age_group' => 'adult'], 'size_id' => $this->sizes['M']->id], jerseyRows($this));
    fillList($this, $this->order, $rows);
    confirmList($this, $this->order)->assertOk();

    $order = $this->order->fresh();
    expect($order->total_minor)->toBe(1200000)
        ->and($order->advance_required_minor)->toBe(360000)
        ->and($order->advanceShortfallMinor())->toBe(60000)
        ->and($order->isProductionEligible())->toBeFalse();
    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum');
    Notification::assertSentTo($this->ops, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum'); // released job: operations told

    verifyPayment($this, recordPayment($this, $order, 60000));
    expect($order->fresh()->isProductionEligible())->toBeTrue();
});
