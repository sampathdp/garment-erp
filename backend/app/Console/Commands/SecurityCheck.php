<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Verifies production-safe configuration. Run after every deploy; exits non-zero on failure.
 */
final class SecurityCheck extends Command
{
    protected $signature = 'app:security-check {--env-only : Skip checks that need the filesystem}';

    protected $description = 'Check that configuration is safe for production';

    public function handle(): int
    {
        $production = $this->laravel->isProduction();
        $failures = [];

        $check = function (bool $ok, string $message) use (&$failures): void {
            $ok ? $this->line("  <info>ok</info>   {$message}") : $failures[] = $message;
            if (! $ok) {
                $this->line("  <error>FAIL</error> {$message}");
            }
        };

        $check(! empty(config('app.key')), 'APP_KEY is set');
        $check(! $production || config('app.debug') === false, 'APP_DEBUG is false in production');
        $check(! $production || str_starts_with((string) config('app.url'), 'https://'), 'APP_URL uses https in production');
        $check(! $production || self::bool('session.secure'), 'SESSION_SECURE_COOKIE is true in production');
        $check(self::bool('session.http_only'), 'Session cookie is HttpOnly');
        $check(self::bool('session.encrypt'), 'Session payload is encrypted');
        $check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session SameSite is lax or strict');
        $check(! in_array('*', (array) config('cors.allowed_origins'), true), 'CORS allowed origins are explicit (no *)');
        $check(self::bool('cors.supports_credentials'), 'CORS supports credentials (required for cookie auth)');
        $check(! $production || ! collect((array) config('sanctum.stateful'))->contains(fn ($d) => str_contains((string) $d, 'localhost')), 'No localhost in SANCTUM_STATEFUL_DOMAINS in production');
        $check(config('filesystems.disks.private.serve') === false && config('filesystems.disks.local.serve') === false, 'Private disks are never auto-served');
        $check(! $production || config('logging.channels.'.config('logging.default').'.level', 'info') !== 'debug', 'Log level is not debug in production');

        if (! $this->option('env-only')) {
            $root = (string) config('filesystems.disks.private.root');
            $check(! str_contains(str_replace('\\', '/', $root), '/public_html') && ! str_starts_with(realpath($root) ?: $root, public_path()), 'Documents root is outside the web root');
        }

        if ($failures !== []) {
            $this->error(count($failures).' security check(s) failed.');

            return self::FAILURE;
        }

        $this->info('All security checks passed.');

        return self::SUCCESS;
    }

    /** env() values may arrive as "1"/"true" strings depending on the loader. */
    private static function bool(string $key): bool
    {
        return filter_var(config($key), FILTER_VALIDATE_BOOLEAN);
    }
}
