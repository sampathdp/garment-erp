<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Organizations\Actions\NextSequenceValue;
use App\Support\Time\BusinessDate;

/**
 * Job numbers: `{BRANCH}-{PAGE}-{YY}-{NNNNN}`, e.g. `CMB-JRSY-26-00042`.
 *
 * - Generated on the server only, inside the order's inserting transaction (NextSequenceValue),
 *   so concurrent orders never share a number and a rolled-back order returns its number.
 * - The running number is company-wide per year, so it is unique on its own; the branch and page
 *   codes are kept for reference. Branch and page codes never change, and `order_no` is never
 *   updated after creation, so historical job numbers stay as issued.
 * - `orders.order_no` is UNIQUE as a final guarantee.
 */
final class GenerateJobNumber
{
    public function __construct(private readonly NextSequenceValue $sequence) {}

    public function handle(string $branchCode, string $pageCode): string
    {
        $year = (int) BusinessDate::today()->format('Y');
        $number = $this->sequence->handle('order', 0, $year);

        return sprintf('%s-%s-%s-%s', strtoupper($branchCode), strtoupper($pageCode), substr((string) $year, -2), str_pad((string) $number, 5, '0', STR_PAD_LEFT));
    }
}
