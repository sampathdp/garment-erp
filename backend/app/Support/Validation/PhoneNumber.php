<?php

declare(strict_types=1);

namespace App\Support\Validation;

use Closure;

/**
 * Phone numbers are stored in E.164 (SECURITY_STANDARDS.md §3). Local Sri Lankan numbers
 * ("077 123 4567") are converted with the +94 country code.
 */
final class PhoneNumber
{
    private const DEFAULT_COUNTRY_CODE = '94';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $digits = preg_replace('/[\s\-().]/', '', trim($value)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        } elseif (preg_match('/^0\d{9}$/', $digits) === 1) {
            $digits = '+'.self::DEFAULT_COUNTRY_CODE.substr($digits, 1);
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
    }

    /** Validation rule: the value must normalise to a valid E.164 number. */
    public static function rule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && $value !== '' && self::normalize($value) === null) {
                $fail('The :attribute must be a valid phone number.');
            }
        };
    }
}
