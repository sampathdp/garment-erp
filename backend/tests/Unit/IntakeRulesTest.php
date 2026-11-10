<?php

declare(strict_types=1);

use App\Modules\Orders\Support\AdvanceRule;
use App\Modules\Orders\Support\UrgencyRule;
use App\Modules\Organizations\Queries\WorkingDays;
use App\Support\Time\BusinessDate;

it('calculates the 30% advance in integer minor units, rounding up', function () {
    expect(AdvanceRule::requiredMinor(1000000, 30))->toBe(300000)
        ->and(AdvanceRule::requiredMinor(100001, 30))->toBe(30001)   // 30,000.30 → 30,001
        ->and(AdvanceRule::requiredMinor(1, 30))->toBe(1)
        ->and(AdvanceRule::requiredMinor(0, 30))->toBe(0)
        ->and(AdvanceRule::requiredMinor(999999999999, 30))->toBe(300000000000)
        ->and(AdvanceRule::requiredMinor(1000000, 45))->toBe(450000);
});

it('applies the seven-day boundary: seven days is normal, six is urgent', function () {
    $rule = new UrgencyRule(new WorkingDays);
    $today = BusinessDate::parse('2026-11-09');

    expect($rule->evaluate($today, BusinessDate::parse('2026-11-16'), 1))->toBe(['days' => 7, 'urgent' => false])
        ->and($rule->evaluate($today, BusinessDate::parse('2026-11-15'), 1))->toBe(['days' => 6, 'urgent' => true])
        ->and($rule->evaluate($today, BusinessDate::parse('2026-11-10'), 1))->toBe(['days' => 1, 'urgent' => true])
        ->and($rule->evaluate($today, BusinessDate::parse('2026-11-09'), 1))->toBe(['days' => 0, 'urgent' => true])
        ->and($rule->evaluate(BusinessDate::parse('2026-12-28'), BusinessDate::parse('2027-01-04'), 1))->toBe(['days' => 7, 'urgent' => false]); // across the year end
});
