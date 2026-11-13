<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Actions;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, never-reused human numbers (job numbers, customer codes, invoices, gate passes).
 *
 * Must run inside the transaction that inserts the numbered row, so a rollback also returns
 * the number. The row is INCREMENTED FIRST: the UPDATE takes the row's exclusive InnoDB lock
 * before the value is read, so two concurrent callers are serialised and can never read the
 * same value. A read-then-write would let both read first.
 *
 * The UPDATE also runs BEFORE any insert: an INSERT IGNORE that hits the existing row takes a
 * shared lock on it, and two callers both holding that shared lock and then asking for the
 * exclusive one deadlock. The row is only inserted the first time a scope/year is used.
 */
final class NextSequenceValue
{
    public function handle(string $scopeType, int $scopeId, int $year): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Sequence numbers must be taken inside the inserting transaction.');
        }

        $key = ['scope_type' => $scopeType, 'scope_id' => $scopeId, 'year' => $year];
        $increment = fn (): int => DB::table('sequences')->where($key)->update(['next_value' => DB::raw('next_value + 1'), 'updated_at' => now()]);

        if ($increment() === 0) {
            DB::table('sequences')->insertOrIgnore($key + ['next_value' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $increment();
        }

        $next = DB::table('sequences')->where($key)->lockForUpdate()->value('next_value');

        return (int) $next - 1;
    }
}
