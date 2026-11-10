<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * 30% advance rule (WORKFLOW_REQUIREMENTS.md §3.2), integer minor units only:
 *
 *   required = ceil(invoice_total × advance_percent / 100)
 *
 * Rounded UP so the advance is never below the percentage (e.g. 30% of LKR 1,000.01 → LKR 300.01).
 */
final class AdvanceRule
{
    public const MINIMUM_PERCENT = 30;

    public static function requiredMinor(int $totalMinor, int $percent): int
    {
        if ($totalMinor <= 0) {
            return 0;
        }

        return intdiv($totalMinor * $percent + 99, 100);
    }
}
