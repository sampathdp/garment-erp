<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data isolation and caching for dashboards and reports.
 *
 *  - orderIds(): the orders the user may see (branch × page scope, BranchScoped), used as a
 *    sub-query by every aggregate, so a count can never include another branch or page.
 *  - remember(): cached results keyed by the user's SCOPE FINGERPRINT (branches, pages, global
 *    flags, permissions) — users with the same scope share entries, and a scope change gives a
 *    new key. Financial writes never read these caches.
 *  - seconds(): portable "end − start" in seconds (MySQL / MariaDB / SQLite).
 */
final class ReportScope
{
    /** @return Builder<Order> */
    public static function orderIds(User $user): Builder
    {
        return Order::query()->visibleTo($user)->select('orders.id');
    }

    public static function fingerprint(User $user, bool $personal = false): string
    {
        return md5(json_encode([
            $personal ? $user->id : null,
            $user->hasAllBranchAccess() ? '*' : $user->assignedBranchIds(),
            $user->hasAllPageAccess() ? '*' : $user->assignedPageIds(),
            $user->permissionNames(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function remember(string $key, int $seconds, callable $callback): mixed
    {
        return Cache::remember("reporting:{$key}", $seconds, $callback);
    }

    public static function seconds(string $start, string $end): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "((julianday({$end}) - julianday({$start})) * 86400)",
            'pgsql' => "EXTRACT(EPOCH FROM ({$end} - {$start}))",
            default => "TIMESTAMPDIFF(SECOND, {$start}, {$end})",
        };
    }
}
