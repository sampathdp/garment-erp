<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    deliveryWorld($this);
});

function section(array $sections, string $key): ?array
{
    return collect($sections)->firstWhere('key', $key);
}

function metric(array $section, string $key): mixed
{
    return collect($section['metrics'])->firstWhere('key', $key)['value'] ?? null;
}

describe('role-specific dashboards', function () {
    it('gives each role its own sections, chosen by permission', function () {
        $cutting = cuttingReadyJob($this);
        assignCutter($this, $cutting)->assertOk();

        $keys = fn ($user) => collect($this->signedInAs($user)->getJson('/api/v1/dashboard')->assertOk()->json('data.sections'))->pluck('key')->all();
        expect($keys($this->admin))->toContain('admin', 'page_owner', 'operations', 'delivery')
            ->and($keys($this->owner))->toBe(['page_owner', 'delivery']) // Page Owners hold delivery.view
            ->and($keys($this->designer))->toBe(['designer'])
            ->and($keys($this->bulkDesigner))->toBe(['bulk_designer'])
            ->and($keys($this->ops))->toBe(['operations', 'delivery'])
            ->and($keys($this->cutter))->toBe(['cutting'])
            ->and($keys($this->driver))->toBe(['delivery']);
        $this->asGuest()->getJson('/api/v1/dashboard')->assertUnauthorized();
    });

    it('shows accurate figures limited to the user\'s scope and assignments', function () {
        $cutting = cuttingReadyJob($this);
        assignCutter($this, $cutting)->assertOk();
        recordCut($this, $cutting)->assertOk();
        reportCuttingIssue($this, $cutting, ['category' => 'damaged_pieces', 'quantity_affected' => 1])->assertCreated();
        approvedOrder($this, ['due_date' => '2026-11-20']); // released, due within 14 days, page A

        $ops = section($this->signedInAs($this->ops)->getJson('/api/v1/dashboard')->json('data.sections'), 'operations');
        expect(metric($ops, 'cutting_open'))->toBe(1)->and(metric($ops, 'open_issues'))->toBe(1)->and(metric($ops, 'blocking_issues'))->toBe(1);

        $owner = section($this->signedInAs($this->owner)->getJson('/api/v1/dashboard')->json('data.sections'), 'page_owner');
        expect(metric($owner, 'new_orders'))->toBe(2)->and(metric($owner, 'pending_payments'))->toBe(0)
            ->and($owner['items'])->not->toBeEmpty();

        // Page B's owner sees nothing of page A.
        $ownerB = section($this->signedInAs($this->ownerB)->getJson('/api/v1/dashboard')->json('data.sections'), 'page_owner');
        expect(metric($ownerB, 'new_orders'))->toBe(0)->and($ownerB['items'])->toBe([]);

        $cutter = section($this->signedInAs($this->cutter)->getJson('/api/v1/dashboard')->json('data.sections'), 'cutting');
        expect(metric($cutter, 'assigned'))->toBe(1)->and(metric($cutter, 'pending_items'))->toBeGreaterThan(0)->and(metric($cutter, 'reported_issues'))->toBe(1);
        $other = section($this->signedInAs($this->otherCutter)->getJson('/api/v1/dashboard')->json('data.sections'), 'cutting');
        expect(metric($other, 'assigned'))->toBe(0)->and(metric($other, 'reported_issues'))->toBe(0);
    });

    it('caches each section briefly instead of recomputing on every poll', function () {
        cuttingReadyJob($this);
        $this->signedInAs($this->ops)->getJson('/api/v1/dashboard')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/dashboard')->assertOk();
        $cached = count(DB::getQueryLog());
        DB::disableQueryLog();

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->signedInAs($this->ops)->getJson('/api/v1/dashboard')->assertOk();
        expect(count(DB::getQueryLog()))->toBeGreaterThan($cached + 5);
        DB::disableQueryLog();
    });
});

describe('reports', function () {
    it('requires reports.view and validates the report and filters', function () {
        $this->signedInAs($this->cutter)->getJson('/api/v1/reports')->assertForbidden();
        $this->signedInAs($this->cutter)->getJson('/api/v1/reports/jobs-by-status')->assertForbidden();
        $this->signedInAs($this->owner)->getJson('/api/v1/reports')->assertOk()->assertJsonCount(12, 'data.reports');
        $this->signedInAs($this->owner)->getJson('/api/v1/reports/nonsense')->assertNotFound();
        $this->signedInAs($this->owner)->getJson('/api/v1/reports/jobs-by-status?filter[from]=2026-11-10&filter[to]=2026-11-01')->assertUnprocessable();
        $this->asGuest()->getJson('/api/v1/reports/jobs-by-status')->assertUnauthorized();
    });

    it('reports job, payment and advance figures accurately and only within scope', function () {
        Cache::flush();
        approvedOrder($this);                                  // 30% verified
        createOrder($this, ['due_date' => '2026-11-25']);      // no verified payment

        $status = $this->signedInAs($this->owner)->getJson('/api/v1/reports/jobs-by-status')->assertOk()->json('data');
        expect($status['total'])->toBe(2)->and(collect($status['rows'])->pluck('total', 'status')->all())
            ->toBe(['pending_payment_verification' => 1, 'confirmed' => 1]);
        expect($this->signedInAs($this->ownerB)->getJson('/api/v1/reports/jobs-by-status')->json('data.total'))->toBe(0);

        $branchPage = $this->signedInAs($this->owner)->getJson('/api/v1/reports/jobs-by-branch-page')->json('data.rows');
        expect($branchPage)->toHaveCount(1)->and($branchPage[0])->toMatchArray(['branch' => 'CMB', 'page' => 'JRSY', 'jobs' => 2]);

        $payments = collect($this->signedInAs($this->owner)->getJson('/api/v1/reports/payment-status')->json('data.rows'))->pluck('jobs', 'bucket')->all();
        expect($payments)->toBe(['no_verified_payment' => 1, 'advance_short' => 0, 'advance_met' => 1, 'paid_in_full' => 0]);

        $advance = $this->signedInAs($this->owner)->getJson('/api/v1/reports/advance-collection')->json('data.rows.0');
        expect($advance['jobs'])->toBe(2)->and($advance['advance_met'])->toBe(1)
            ->and($advance['collected']['amount_minor'])->toBe(300000)->and($advance['shortfall']['amount_minor'])->toBe(300000);

        $outstanding = $this->signedInAs($this->owner)->getJson('/api/v1/reports/outstanding-balance')->assertOk()->json();
        expect($outstanding['meta']['total'])->toBe(2)->and($outstanding['data']['rows'][0]['outstanding']['amount_minor'])->toBe(1000000)
            ->and($outstanding['data']['totals'][0]['outstanding']['amount_minor'])->toBe(1000000 + 700000);
    });

    it('lists overdue and upcoming jobs with pagination', function () {
        approvedOrder($this, ['due_date' => '2026-11-20']);
        approvedOrder($this, ['due_date' => '2026-12-20']);

        $upcoming = $this->signedInAs($this->owner)->getJson('/api/v1/reports/upcoming-due?filter[days]=14')->assertOk()->json();
        expect($upcoming['meta']['total'])->toBe(1)->and($upcoming['data']['rows'][0]['due_date'])->toBe('2026-11-20');
        expect($this->signedInAs($this->owner)->getJson('/api/v1/reports/overdue-jobs')->json('meta.total'))->toBe(0);

        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Colombo'));
        $overdue = $this->signedInAs($this->owner)->getJson('/api/v1/reports/overdue-jobs?per_page=1')->assertOk()->json();
        expect($overdue['meta']['total'])->toBe(1)->and($overdue['data']['rows'][0]['days_overdue'])->toBe(3);
        expect($this->signedInAs($this->ownerB)->getJson('/api/v1/reports/overdue-jobs')->json('meta.total'))->toBe(0);
    });

    it('measures stage completion times and summarises production issues', function () {
        $cutting = readyToCompleteCutting($this);
        reportCuttingIssue($this, $cutting, ['category' => 'machine', 'description' => 'Blade replaced'], $this->ops)->assertCreated();
        $issueId = reportCuttingIssue($this, $cutting, ['category' => 'damaged_pieces', 'quantity_affected' => 2])->assertCreated()->json('data.id');
        $this->travel(3)->hours();
        moveIssue($this, $issueId, 'resolved', ['resolution' => 'Recut']);
        completeCutting($this, $cutting)->assertOk();
        Cache::flush();

        foreach (['design-completion-time', 'bulk-completion-time', 'printing-completion-time', 'cutting-completion-time'] as $report) {
            $summary = $this->signedInAs($this->owner)->getJson("/api/v1/reports/{$report}")->assertOk()->json('data.summary');
            expect($summary['completed'])->toBe(1, $report)->and($summary['average_hours'])->toBeGreaterThanOrEqual(0.0, $report);
        }
        expect($this->signedInAs($this->owner)->getJson('/api/v1/reports/cutting-completion-time')->json('data.summary.average_hours'))->toBeGreaterThanOrEqual(3.0);

        $issues = $this->signedInAs($this->owner)->getJson('/api/v1/reports/issue-summary')->assertOk()->json('data');
        expect(collect($issues['rows'])->pluck('total', 'category')->all())->toEqualCanonicalizing(['machine' => 1, 'damaged_pieces' => 1])
            ->and(collect($issues['rows'])->firstWhere('category', 'damaged_pieces')['pieces_affected'])->toBe(2)
            ->and($issues['by_status'])->toBe(['open' => 1, 'resolved' => 1])
            ->and($issues['average_resolution_hours'])->toBeGreaterThanOrEqual(3.0);
        expect($this->signedInAs($this->ownerB)->getJson('/api/v1/reports/issue-summary')->json('data.rows'))->toBe([]);
        expect(Order::query()->count())->toBe(1);
    });
});
