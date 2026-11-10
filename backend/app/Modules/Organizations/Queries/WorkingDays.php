<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Queries;

use App\Modules\Organizations\Models\Holiday;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Working-day counting for the urgent rule (client decision: working days, WORKFLOW_REQUIREMENTS.md §3.3).
 *
 * Counts the days AFTER $from up to and INCLUDING $to that are working weekdays
 * (`working_calendar_days`) and not company-wide or branch holidays. When no working week is
 * configured, every weekday is a working day, so the count equals calendar days.
 *
 *   from 2026-11-09, to 2026-11-16, no days off  → 7
 *   from 2026-11-09, to 2026-11-15                → 6
 */
final class WorkingDays
{
    public function between(CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): int
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        if ($to <= $from) {
            return 0;
        }

        $nonWorkingWeekdays = WorkingCalendarDay::query()->where('is_working_day', false)->pluck('iso_weekday')
            ->map(fn ($d): int => (int) $d)->all();

        $holidays = Holiday::query()
            ->whereBetween('holiday_date', [$from->addDay()->toDateString(), $to->toDateString()])
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId !== null, fn (Builder $b) => $b->orWhere('branch_id', $branchId)))
            ->pluck('holiday_date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))
            ->flip();

        $count = 0;
        for ($day = $from->addDay(); $day <= $to; $day = $day->addDay()) {
            if (! in_array($day->isoWeekday(), $nonWorkingWeekdays, true) && ! $holidays->has($day->toDateString())) {
                $count++;
            }
        }

        return $count;
    }
}
