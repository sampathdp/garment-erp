<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, never-reused human numbers (job numbers, customer codes, invoices, gate passes).
 *
 * Must run inside the transaction that inserts the numbered row, so a rollback also returns
 * the number.
 *
 * One INSERT ... ON DUPLICATE KEY UPDATE creates or increments the row. When the row exists,
 * InnoDB takes its EXCLUSIVE lock straight away (a plain INSERT IGNORE would take a shared one),
 * so concurrent callers are serialised and can never read the same value. When it does not
 * exist yet, no gap lock is taken first: an UPDATE that matches nothing followed by an INSERT
 * lets two callers each hold a gap lock and then wait for the other's, which deadlocks the
 * first time a scope/year is used (tests/Feature/Orders/JobNumberTest.php).
 */
final class NextSequenceValue
{
    public function handle(string $scopeType, int $scopeId, int $year): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Sequence numbers must be taken inside the inserting transaction.');
        }

        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'year' => $year];
        $now = now();

        // A new row hands out 1 and stores 2; an existing row hands out next_value and stores next_value + 1.
        DB::table('sequences')->upsert(
            [$key + ['next_value' => 2, 'created_at' => $now, 'updated_at' => $now]],
            array_keys($key),
            ['next_value' => DB::raw('next_value + 1'), 'updated_at' => $now],
        );

        $next = DB::table('sequences')->where($key)->lockForUpdate()->value('next_value');

        return (int) $next - 1;
    }
}
