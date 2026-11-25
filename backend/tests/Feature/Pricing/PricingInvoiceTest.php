<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Permissions;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Notifications\WorkflowNotification;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

beforeEach(function () {
    bulkWorld($this);
    $this->order = approvedOrder($this);
});

/** The order of a print job, completed with the given printed quantities (null = as listed). */
function invoiceAfterPrinting(TestCase $test, ?array $groups = null, array $overrides = []): array
{
    $job = printingInProgress($test, $overrides);
    ($groups === null ? completePrinting($test, $job) : completePrintingByGroup($test, $job, $groups))->assertOk();
    $invoice = Invoice::query()->where('order_id', $job->order_id)->sole();

    return [$job, $invoice];
}

it('issues no invoice when the list is confirmed: the price is pending until printing', function () {
    fillList($this, $this->order);
    confirmList($this, $this->order)->assertOk()->assertJsonMissingPath('data.invoice_id');

    expect(Invoice::query()->count())->toBe(0);
    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$this->order->id}")
        ->assertJsonPath('data.pricing.total_minor', 0)->assertJsonPath('data.pricing.price_pending', true);
});

it('prices each attribute group at Printing Completed and issues an invoice snapshot', function () {
    [$job, $invoice] = invoiceAfterPrinting($this); // 6 short adult (1,000) + 4 long kids (950), printed as listed

    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()
        ->assertJsonPath('data.invoice_no', $this->pageA->invoice_prefix.'-2026-000001')
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.lines.0.quantity', 6)->assertJsonPath('data.lines.0.unit_price_minor', 100000)
        ->assertJsonPath('data.lines.1.quantity', 4)->assertJsonPath('data.lines.1.unit_price_minor', 95000)
        ->assertJsonPath('data.subtotal_minor', 980000)
        ->assertJsonPath('data.tax_minor', 0)
        ->assertJsonPath('data.total_minor', 980000)
        ->assertJsonPath('data.payments.advance_required_minor', 300000) // the fixed advance, unchanged
        ->assertJsonPath('data.payments.verified_paid_minor', 300000)
        ->assertJsonPath('data.payments.balance_minor', 680000)
        ->assertJsonPath('data.pricing_date', '2026-11-09');

    $this->signedInAs($this->owner)->getJson("/api/v1/orders/{$job->order_id}")
        ->assertJsonPath('data.pricing.total_minor', 980000)->assertJsonPath('data.pricing.price_pending', false);
});

it('prices the printed quantity per group, with the tier from the printed total', function () {
    $tierRule = PriceListItem::query()->create(['price_list_id' => $this->priceList->id, 'product_type_id' => $this->jersey->id, 'min_qty' => 12, 'unit_price_minor' => 90000, 'label' => 'short adult 12+']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $tierRule->id, 'field_key' => 'sleeve_type', 'option_code' => 'short']);
    PriceListItemCondition::query()->create(['price_list_item_id' => $tierRule->id, 'field_key' => 'age_group', 'option_code' => 'adult']);

    // 9 short adult + 4 long kids printed (13 in total → the 12+ tier for short adult).
    [$job, $invoice] = invoiceAfterPrinting($this, ['Jersey — Short sleeve, Adult' => 9, 'Jersey — Long sleeve, Kids' => 4]);

    $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('sort_order')->get(['quantity', 'unit_price_minor'])->toArray();
    expect($lines)->toBe([['quantity' => 9, 'unit_price_minor' => 90000], ['quantity' => 4, 'unit_price_minor' => 95000]])
        ->and($invoice->total_minor)->toBe(1190000)
        ->and($job->fresh()->printed_quantity)->toBe(13);
});

it('needs every group, or a total equal to the list, to complete printing', function () {
    $job = printingInProgress($this);

    completePrinting($this, $job, 9)->assertStatus(422)->assertJsonValidationErrors('groups');
    completePrintingByGroup($this, $job, ['Jersey — Short sleeve, Adult' => 6])->assertStatus(422)->assertJsonValidationErrors('groups');
    completePrintingByGroup($this, $job, ['Jersey — Short sleeve, Adult' => 0, 'Jersey — Long sleeve, Kids' => 0])->assertStatus(422);
    expect(Invoice::query()->count())->toBe(0)->and($job->fresh()->status->value)->toBe('physical_printing');
});

it('shows the list groups on the print job while printing is in progress', function () {
    $job = printingInProgress($this);

    expect(printJob($this, $job)['printing']['price_groups'])->toBe([
        ['label' => 'Jersey — Short sleeve, Adult', 'quantity' => 6],
        ['label' => 'Jersey — Long sleeve, Kids', 'quantity' => 4],
    ]);
});

it('keeps invoices inside their page', function () {
    [, $invoice] = invoiceAfterPrinting($this);

    $this->signedInAs($this->ownerB)->getJson("/api/v1/invoices/{$invoice->id}")->assertNotFound();
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

it('refuses to complete printing when a printed group has no price', function () {
    PriceListItem::query()->where('label', 'long|kids')->delete();
    $job = printingInProgress($this); // the list can still be confirmed: nothing is priced there

    completePrinting($this, $job)->assertStatus(409)->assertJsonPath('code', 'pricing_missing')
        ->assertJsonFragment(['No price for Jersey — Long sleeve, Kids.']);
    expect(Invoice::query()->count())->toBe(0)
        ->and($job->fresh()->status->value)->toBe('physical_printing'); // nothing completed
});

it('never changes an issued invoice when prices change, and freezes the used price list', function () {
    [, $invoice] = invoiceAfterPrinting($this);
    $invoiceId = $invoice->id;
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
    [, $invoice] = invoiceAfterPrinting($this, overrides: ['discount_minor' => 80000, 'customer' => ['name' => 'Discounted', 'phone' => '0779990000', 'whatsapp' => '0779990000']]);
    $invoiceId = $invoice->id;

    // (980,000 − 80,000) × 18% = 162,000 tax
    $this->signedInAs($this->owner)->getJson("/api/v1/invoices/{$invoiceId}")
        ->assertJsonPath('data.discount_minor', 80000)->assertJsonPath('data.tax_percent', 18)
        ->assertJsonPath('data.tax_minor', 162000)->assertJsonPath('data.total_minor', 1062000);
});

it('keeps the agreed advance after the invoice and lets the balance follow it', function () {
    Notification::fake();
    [$job] = invoiceAfterPrinting($this);

    $order = $job->order()->firstOrFail();
    expect($order->total_minor)->toBe(980000)
        ->and($order->advance_required_minor)->toBe(300000)
        ->and($order->isProductionEligible())->toBeTrue()
        ->and($order->total_minor - $order->verified_paid_minor)->toBe(680000);
    Notification::assertSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'invoice.issued');
    Notification::assertNotSentTo($this->owner, WorkflowNotification::class, fn (WorkflowNotification $n) => $n->event === 'advance.below_minimum');
});
