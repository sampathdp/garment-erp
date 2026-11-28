<?php

declare(strict_types=1);

use App\Modules\Identity\Support\Permissions;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Page;
use App\Modules\Pricing\Models\ProductType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

beforeEach(function () {
    designWorld($this); // today = 2026-11-09 (Colombo); owner → page A, ownerB → page B, admin = Super Admin
    $this->shorts = ProductType::query()->create(['code' => 'shorts_plan', 'name' => 'Shorts', 'size_chart_id' => $this->jersey->size_chart_id, 'is_active' => true]);
});

/**
 * One order line inserted directly (fast fixture). $confirmedQty set = design task completed and
 * the current list version locked with that total; $listLocked/$designDone override either half.
 */
function planLine(TestCase $test, Page $page, string $due, int $qty, ?ProductType $type = null, OrderStatus $status = OrderStatus::Confirmed, ?int $confirmedQty = null, ?bool $designDone = null, ?bool $listLocked = null, ?Branch $branch = null, ?OrderStatus $heldFrom = null): int
{
    $order = $test->makeOrder($branch ?? $test->branch, $page, $status);
    DB::table('orders')->where('id', $order->id)->update(['required_delivery_date' => $due, 'status_before_hold' => $heldFrom?->value]);
    $itemId = DB::table('order_items')->insertGetId([
        'order_id' => $order->id, 'product_type_id' => ($type ?? $test->jersey)->id, 'quantity' => $qty,
        'unit_price_minor' => 0, 'line_total_minor' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $designDone ??= $confirmedQty !== null;
    $listLocked ??= $confirmedQty !== null;
    if ($designDone || $confirmedQty !== null) {
        DB::table('design_tasks')->insert([
            'order_id' => $order->id, 'order_item_id' => $itemId, 'title' => 'Design', 'version' => 1,
            'status' => $designDone ? 'completed' : 'in_progress', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    if ($listLocked || $confirmedQty !== null) {
        $listId = DB::table('garment_lists')->insertGetId(['order_item_id' => $itemId, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $versionId = DB::table('garment_list_versions')->insertGetId([
            'garment_list_id' => $listId, 'version_no' => 1, 'status' => $listLocked ? 'locked' : 'draft',
            'total_quantity' => $confirmedQty ?? $qty, 'created_by' => $test->admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('garment_lists')->where('id', $listId)->update(['current_version_id' => $versionId]);
    }

    return $order->id;
}

function plan(TestCase $test, $user, string $query = ''): array
{
    return $test->signedInAs($user)->getJson('/api/v1/production-plan'.$query)->assertOk()->json('data');
}

function planDay(array $plan, string $date): ?array
{
    return collect($plan['days'])->firstWhere('date', $date);
}

function planCategory(array $day, string $name): ?array
{
    return collect($day['categories'])->firstWhere('product_type.name', $name);
}

describe('access', function () {
    it('requires authentication and a production plan permission', function () {
        $this->asGuest()->getJson('/api/v1/production-plan')->assertUnauthorized();
        $this->signedInAs($this->ops)->getJson('/api/v1/production-plan')->assertForbidden(); // Operation Admin: not granted
        $this->signedInAs($this->designer)->getJson('/api/v1/production-plan')->assertForbidden();
        $this->signedInAs($this->owner)->getJson('/api/v1/production-plan')->assertOk();
        $this->signedInAs($this->admin)->getJson('/api/v1/production-plan')->assertOk();
    });

    it('grants the plan to Page Owners, and the page breakdown only through view_all_pages', function () {
        expect($this->owner->can(Permissions::PRODUCTION_PLAN_VIEW))->toBeTrue()
            ->and($this->owner->can(Permissions::PRODUCTION_PLAN_VIEW_ALL_PAGES))->toBeFalse()
            ->and($this->admin->can(Permissions::PRODUCTION_PLAN_VIEW_ALL_PAGES))->toBeTrue();
        $viewer = $this->userWith([Permissions::PRODUCTION_PLAN_VIEW_ALL_PAGES, Permissions::SCOPE_ALL_BRANCHES]);
        expect(plan($this, $viewer)['page_names'])->toBe('all');
    });

    it('validates the date range', function () {
        $this->signedInAs($this->owner)->getJson('/api/v1/production-plan?filter[from]=09-11-2026')->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        $this->signedInAs($this->owner)->getJson('/api/v1/production-plan?filter[from]=2026-11-20&filter[to]=2026-11-10')->assertUnprocessable()->assertJsonValidationErrors('filter.to');
        $this->signedInAs($this->owner)->getJson('/api/v1/production-plan?filter[from]=2026-11-09&filter[to]=2027-06-01')->assertUnprocessable()->assertJsonValidationErrors('filter.to');
        $this->signedInAs($this->owner)->getJson('/api/v1/production-plan?filter[to]=2026-12-01')->assertOk();
    });
});

describe('grouping and colours', function () {
    it('groups by due date and category, splitting temporary (estimate) from confirmed (locked list) quantities', function () {
        planLine($this, $this->pageA, '2026-12-01', 20);                      // temporary: estimate 20
        planLine($this, $this->pageB, '2026-12-01', 20, confirmedQty: 23);    // confirmed: locked list 23
        planLine($this, $this->pageA, '2026-12-01', 5, $this->shorts);
        planLine($this, $this->pageB, '2026-12-10', 7, $this->shorts, confirmedQty: 7);

        $data = plan($this, $this->admin);
        $first = planDay($data, '2026-12-01');
        expect(array_column($data['days'], 'date'))->toBe(['2026-12-01', '2026-12-10'])
            ->and(planCategory($first, 'Jersey'))->toMatchArray(['temporary' => 20, 'confirmed' => 23, 'total' => 43])
            ->and(planCategory($first, 'Shorts'))->toMatchArray(['temporary' => 5, 'confirmed' => 0, 'total' => 5])
            ->and($first['totals'])->toBe(['temporary' => 25, 'confirmed' => 23, 'total' => 48])
            ->and(planCategory(planDay($data, '2026-12-10'), 'Shorts'))->toMatchArray(['temporary' => 0, 'confirmed' => 7])
            ->and($data['totals'])->toBe(['temporary' => 25, 'confirmed' => 30, 'total' => 55]);
    });

    it('needs both a completed design and a locked list to confirm a line', function () {
        planLine($this, $this->pageA, '2026-12-01', 10, designDone: true, listLocked: false);
        planLine($this, $this->pageA, '2026-12-01', 10, designDone: false, listLocked: true);

        expect(planCategory(planDay(plan($this, $this->admin), '2026-12-01'), 'Jersey'))->toMatchArray(['temporary' => 20, 'confirmed' => 0]);
    });

    it('turns a real order from temporary to confirmed once its design is completed and its list confirmed', function () {
        $order = approvedOrder($this, ['due_date' => '2026-11-30']); // 10 jerseys
        $task = submitJobDetails($this, $order);
        expect(planCategory(planDay(plan($this, $this->owner), '2026-11-30'), 'Jersey'))->toMatchArray(['temporary' => 10, 'confirmed' => 0]);

        completeDesign($this, $task);
        fillList($this, $order);
        expect(planCategory(planDay(plan($this, $this->owner), '2026-11-30'), 'Jersey'))->toMatchArray(['temporary' => 10, 'confirmed' => 0]);

        confirmList($this, $order)->assertOk();
        expect(planCategory(planDay(plan($this, $this->owner), '2026-11-30'), 'Jersey'))->toMatchArray(['temporary' => 0, 'confirmed' => 10, 'total' => 10]);
    });
});

describe('what counts', function () {
    it('counts orders from creation (drafts too) until sewing is done', function () {
        planLine($this, $this->pageA, '2026-12-01', 1, status: OrderStatus::Draft);
        planLine($this, $this->pageA, '2026-12-01', 2, status: OrderStatus::PendingPaymentVerification);
        planLine($this, $this->pageA, '2026-12-01', 4, status: OrderStatus::Sewing);
        planLine($this, $this->pageA, '2026-12-01', 8, status: OrderStatus::OnHold, heldFrom: OrderStatus::Cutting);
        foreach ([OrderStatus::Cancelled, OrderStatus::ReadyForDelivery, OrderStatus::OutForDelivery, OrderStatus::Delivered, OrderStatus::Completed] as $gone) {
            planLine($this, $this->pageA, '2026-12-01', 100, status: $gone);
        }
        planLine($this, $this->pageA, '2026-12-01', 100, status: OrderStatus::OnHold, heldFrom: OrderStatus::ReadyForDelivery);

        expect(planDay(plan($this, $this->admin), '2026-12-01')['totals']['total'])->toBe(15);
    });

    it('creates the plan entry the moment an order is created through the API', function () {
        createOrder($this, ['due_date' => '2026-11-30', 'gross_quantity' => 12, 'submit' => false]);

        expect(planCategory(planDay(plan($this, $this->ownerB), '2026-11-30'), 'Jersey'))->toMatchArray(['temporary' => 12, 'total' => 12]);
    });

    it('shows overdue dates when the range starts today, and limits dates to the range', function () {
        planLine($this, $this->pageA, '2026-11-01', 3);  // overdue, still in production
        planLine($this, $this->pageA, '2026-11-20', 4);
        planLine($this, $this->pageA, '2027-03-01', 9);  // beyond the default 60 days

        $default = plan($this, $this->admin);
        expect(array_column($default['days'], 'date'))->toBe(['2026-11-01', '2026-11-20'])
            ->and(planDay($default, '2026-11-01')['is_overdue'])->toBeTrue()
            ->and(planDay($default, '2026-11-20')['is_overdue'])->toBeFalse();

        $later = plan($this, $this->admin, '?filter[from]=2026-11-15&filter[to]=2027-03-01');
        expect(array_column($later['days'], 'date'))->toBe(['2026-11-20', '2027-03-01']);
    });

    it('still respects branch scope', function () {
        $other = Branch::factory()->create(['organization_id' => $this->branch->organization_id, 'code' => 'KDY']);
        planLine($this, $this->pageA, '2026-12-01', 5);
        planLine($this, $this->pageA, '2026-12-01', 50, branch: $other);
        $branchOnly = $this->userWith([Permissions::PRODUCTION_PLAN_VIEW], [$this->branch], [$this->pageA]);

        expect(planDay(plan($this, $branchOnly), '2026-12-01')['totals']['total'])->toBe(5)
            ->and(planDay(plan($this, $this->owner), '2026-12-01')['totals']['total'])->toBe(55); // all branches
    });
});

describe('privacy', function () {
    it('shows page owners the combined load with their own pages named and every other page anonymous', function () {
        planLine($this, $this->pageA, '2026-12-01', 40);
        planLine($this, $this->pageB, '2026-12-01', 10, confirmedQty: 10);
        planLine($this, $this->pageB, '2026-12-05', 6);

        $response = $this->signedInAs($this->owner)->getJson('/api/v1/production-plan')->assertOk();
        $data = $response->json('data');
        $jersey = planCategory(planDay($data, '2026-12-01'), 'Jersey');
        expect($data['page_names'])->toBe('own')
            ->and($data['own_pages'])->toBe([['id' => $this->pageA->id, 'code' => 'JRSY', 'name' => $this->pageA->name]])
            ->and($jersey)->toMatchArray(['temporary' => 40, 'confirmed' => 10, 'total' => 50])
            ->and($jersey['pages'])->toHaveCount(1)
            ->and($jersey['pages'][0])->toMatchArray(['is_own' => true, 'temporary' => 40, 'total' => 40])
            ->and($jersey['pages'][0]['page']['id'])->toBe($this->pageA->id)
            ->and($jersey['other_pages'])->toBe(['temporary' => 0, 'confirmed' => 10, 'total' => 10])
            ->and(planCategory(planDay($data, '2026-12-05'), 'Jersey')['pages'])->toBe([]);

        // Nothing identifying page B (or any customer / job) is in the payload.
        $raw = $response->getContent();
        expect($raw)->not->toContain('KITS')->not->toContain($this->pageB->name)->not->toContain('Test Customer')->not->toContain('JOB-')
            ->and(collect($data['days'])->flatMap(fn ($d) => collect($d['categories'])->flatMap(fn ($c) => array_column(array_column($c['pages'], 'page'), 'id')))->unique()->values()->all())
            ->toBe([$this->pageA->id]);
    });

    it('shows every page by name to holders of view_all_pages', function () {
        planLine($this, $this->pageA, '2026-12-01', 40);
        planLine($this, $this->pageB, '2026-12-01', 10);

        $jersey = planCategory(planDay(plan($this, $this->admin), '2026-12-01'), 'Jersey');
        expect($jersey['other_pages'])->toBeNull()
            ->and(collect($jersey['pages'])->mapWithKeys(fn ($p) => [$p['page']['code'] => $p['total']])->all())->toBe(['JRSY' => 40, 'KITS' => 10]);
    });

    it('runs a fixed number of queries however many lines there are', function () {
        foreach (range(1, 12) as $i) {
            planLine($this, $i % 2 ? $this->pageA : $this->pageB, '2026-12-'.str_pad((string) (1 + $i % 5), 2, '0', STR_PAD_LEFT), $i, $i % 3 ? $this->jersey : $this->shorts, confirmedQty: $i % 4 ? null : $i);
        }
        $this->owner->assignedPageIds();
        $this->owner->assignedBranchIds();

        DB::enableQueryLog();
        plan($this, $this->owner);
        $log = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($log->filter(fn (string $q) => Str::contains($q, 'order_items'))->count())->toBe(1) // one aggregate
            ->and($log->filter(fn (string $q) => Str::contains($q, 'from `pages`') && ! Str::contains($q, 'join'))->count())->toBe(1); // one page-label lookup
    });
});
