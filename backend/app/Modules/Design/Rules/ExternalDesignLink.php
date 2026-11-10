<?php

declare(strict_types=1);

namespace App\Modules\Design\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A shared design link (e.g. Google Drive). The link is stored and shown to staff only: the
 * server NEVER fetches it, so it cannot be used for server-side request forgery. As defence in
 * depth it must be https, on an allow-listed host (config documents.design_link_hosts), without
 * credentials or a custom port. Entering a link does not mean a Drive integration exists.
 */
final class ExternalDesignLink implements ValidationRule
{
    /** @param  string  $hostsConfig  config key of the host allow-list (design links, WeTransfer links, ...) */
    public function __construct(private readonly string $hostsConfig = 'documents.design_link_hosts') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) > 500 || preg_match('/[\s\x00-\x1F\x7F<>"\'\\\\]/', $value) === 1) {
            $fail('Enter a valid link.');

            return;
        }

        $parts = parse_url($value);
        $hosts = array_map('strtolower', (array) config($this->hostsConfig, []));

        $valid = is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && in_array(strtolower($parts['host']), $hosts, true)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || $parts['port'] === 443);

        if (! $valid) {
            $fail('Use an https link from: '.implode(', ', $hosts).'.');
        }
    }
}
