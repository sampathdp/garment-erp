<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Exponential lockout per email + IP (SECURITY_STANDARDS.md §1), on top of the per-minute
 * `login` rate limiter. After every `threshold` consecutive failures the pair is locked for
 * base × 2^(lockouts − 1) seconds, capped at `max_seconds`. A successful login clears it.
 * Uses the cache store (database on shared hosting, Redis on a VPS).
 */
final class LoginLockout
{
    private const TTL_SECONDS = 86_400;

    public function __construct(private readonly Cache $cache) {}

    /** Seconds until the pair may try again (0 = not locked). */
    public function secondsRemaining(string $email, ?string $ip): int
    {
        $state = $this->state($email, $ip);

        return max(0, $state['locked_until'] - now()->getTimestamp());
    }

    public function recordFailure(string $email, ?string $ip): void
    {
        $state = $this->state($email, $ip);
        $state['failures']++;

        if ($state['failures'] >= (int) config('api.login_lockout.threshold', 5)) {
            $state['lockouts']++;
            $state['failures'] = 0;
            $seconds = (int) config('api.login_lockout.base_seconds', 60) * (2 ** ($state['lockouts'] - 1));
            $state['locked_until'] = now()->getTimestamp() + min($seconds, (int) config('api.login_lockout.max_seconds', 3600));
        }

        $this->cache->put($this->key($email, $ip), $state, self::TTL_SECONDS);
    }

    public function clear(string $email, ?string $ip): void
    {
        $this->cache->forget($this->key($email, $ip));
    }

    /** @return array{failures: int, lockouts: int, locked_until: int} */
    private function state(string $email, ?string $ip): array
    {
        $state = $this->cache->get($this->key($email, $ip));

        return is_array($state)
            ? ['failures' => (int) ($state['failures'] ?? 0), 'lockouts' => (int) ($state['lockouts'] ?? 0), 'locked_until' => (int) ($state['locked_until'] ?? 0)]
            : ['failures' => 0, 'lockouts' => 0, 'locked_until' => 0];
    }

    private function key(string $email, ?string $ip): string
    {
        return 'login-lockout:'.hash('sha256', mb_strtolower($email).'|'.$ip);
    }
}
