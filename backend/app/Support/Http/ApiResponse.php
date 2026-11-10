<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response envelope helpers (API_STANDARDS.md §3).
 *
 *   single     { "data": {...} }
 *   paginated  { "data": [...], "meta": { page, per_page, total, last_page }, "links": { next, prev } }
 */
final class ApiResponse
{
    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  class-string<JsonResource>  $resource
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resource): JsonResponse
    {
        return new JsonResponse([
            'data' => $resource::collection($paginator->items())->resolve(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'next' => $paginator->nextPageUrl(),
                'prev' => $paginator->previousPageUrl(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse(['data' => $data], $status);
    }
}
