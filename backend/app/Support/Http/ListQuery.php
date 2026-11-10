<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pagination and whitelisted sorting for list endpoints.
 * Sort syntax: ?sort=name or ?sort=-created_at. Unknown fields are rejected (422), never interpolated.
 */
final class ListQuery
{
    public static function perPage(Request $request): int
    {
        $default = (int) config('api.pagination.default_per_page', 25);
        $max = (int) config('api.pagination.max_per_page', 100);
        $value = $request->query('per_page');

        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            throw ValidationException::withMessages(['per_page' => ["per_page must be an integer between 1 and {$max}."]]);
        }

        return (int) $value;
    }

    /**
     * @param  array<string, string>  $allowed  public sort key => column
     */
    public static function applySort(Builder $query, Request $request, array $allowed, string $default): Builder
    {
        $sort = $request->query('sort', $default);
        if (! is_string($sort) || $sort === '') {
            $sort = $default;
        }

        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! array_key_exists($key, $allowed)) {
            throw ValidationException::withMessages(['sort' => ['Unsupported sort field. Allowed: '.implode(', ', array_keys($allowed)).'.']]);
        }

        return $query->orderBy($allowed[$key], $direction);
    }
}
