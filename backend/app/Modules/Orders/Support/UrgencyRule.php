<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

use App\Modules\Organizations\Queries\WorkingDays;
use Carbon\CarbonImmutable;

/**
 * Seven-day rule (WORKFLOW_REQUIREMENTS.md §3.3).
 *
 * days = working days AFTER the reference date (submission date, or the date the due date was
 *        changed; Asia/Colombo) up to and INCLUDING the due date. Without a configured working
 *        week or holidays this equals calendar days.
 *
 *   days ≥ 7  → normal (exactly seven days follows the normal process)
 *   days < 7  → urgent: needs the Second Admin AND the Operation Admin approval
 */
final class UrgencyRule
{
    public const THRESHOLD_DAYS = 7;

    public function __construct(private readonly WorkingDays $workingDays) {}

    /** @return array{days: int, urgent: bool} */
    public function evaluate(CarbonImmutable $referenceDate, CarbonImmutable $dueDate, int $branchId): array
    {
        $days = $this->workingDays->between($referenceDate, $dueDate, $branchId);

        return ['days' => $days, 'urgent' => $days < self::THRESHOLD_DAYS];
    }
}
