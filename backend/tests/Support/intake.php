<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Roles;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderType;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use App\Modules\Payments\Models\Payment;
use App\Modules\Pricing\Models\ProductType;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Shared fixtures for the order-intake tests (Phase 03).
 *
 * "Today" is frozen at 2026-11-09 10:00 Asia/Colombo. Page A has a Page Owner, a Second Admin
 * and the company-wide Operation Admin; page B has its own Page Owner and Second Admin.
 */
function intakeWorld(TestCase $test): void
{
    $test->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00', 'Asia/Colombo'));
    $test->seed(PermissionSeeder::class);
    $test->seed(CatalogSeeder::class);

    $org = Organization::factory()->create();
    $test->branch = Branch::factory()->create(['organization_id' => $org->id, 'code' => 'CMB']);
    $test->pageA = Page::factory()->create(['organization_id' => $org->id, 'code' => 'JRSY', 'default_advance_percent' => 30, 'default_delivery_fee_minor' => 0]);
    $test->pageB = Page::factory()->create(['organization_id' => $org->id, 'code' => 'KITS']);

    $test->owner = $test->userWithRole(Roles::PAGE_OWNER, pages: [$test->pageA]);
    $test->owner->pages()->updateExistingPivot($test->pageA->id, ['is_owner' => true]);
    $test->second = $test->userWithRole(Roles::SECOND_ADMIN, pages: [$test->pageA]);
    $test->ops = $test->userWithRole(Roles::OPERATION_ADMIN);
    $test->ownerB = $test->userWithRole(Roles::PAGE_OWNER, pages: [$test->pageB]);
    $test->secondB = $test->userWithRole(Roles::SECOND_ADMIN, pages: [$test->pageB]);

    $test->single = OrderType::query()->where('code', 'single')->firstOrFail();
    $test->jersey = ProductType::query()->where('code', 'jersey')->firstOrFail();
}

/** Order form payload: 10 jerseys × LKR 1,000.00 = LKR 10,000.00 total → 30% advance LKR 3,000.00. */
function orderPayload(TestCase $test, array $overrides = []): array
{
    return array_replace_recursive([
        'branch_id' => $test->branch->id,
        'page_id' => $test->pageA->id,
        'customer' => ['name' => 'Nimal Perera', 'phone' => '0771234567', 'whatsapp' => '0771234567', 'other_phone' => null, 'address' => '12 Galle Road, Colombo 03'],
        'order_type_id' => $test->single->id,
        'due_date' => '2026-11-30',
        'product_type_id' => $test->jersey->id,
        'gross_quantity' => 10,
        'unit_price_minor' => 100000,
        'submit' => true,
    ], $overrides);
}

function createOrder(TestCase $test, array $overrides = [], ?User $as = null): Order
{
    $id = $test->signedInAs($as ?? $test->owner)->postJson('/api/v1/orders', orderPayload($test, $overrides))->assertCreated()->json('data.id');

    return Order::query()->findOrFail($id);
}

function slip(): UploadedFile
{
    // Minimal valid PNG (content-checked by AllowedDocument).
    return UploadedFile::fake()->createWithContent('slip.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
}

function recordPayment(TestCase $test, Order $order, int $amountMinor, ?User $as = null, array $extra = []): Payment
{
    $id = $test->signedInAs($as ?? $test->owner)->post("/api/v1/orders/{$order->id}/payments", [
        'amount_minor' => $amountMinor, 'method' => 'bank_transfer', 'reference_no' => 'BOC-'.random_int(1000, 9999),
        'payment_date' => '2026-11-09', 'evidence' => slip(), ...$extra,
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

    return Payment::query()->findOrFail($id);
}

function verifyPayment(TestCase $test, Payment $payment, ?User $as = null): void
{
    $test->signedInAs($as ?? $test->second)->postJson("/api/v1/payments/{$payment->id}/verify", ['notes' => 'Seen in bank statement'])->assertOk();
}
