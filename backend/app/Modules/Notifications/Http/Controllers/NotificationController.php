<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The caller's own notifications. Polled by the frontend every 30 s (shared hosting: no WebSockets).
 * Queries are always constrained to the authenticated user, so no record-level policy applies.
 */
final class NotificationController
{
    public function unreadCount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::data(['unread' => $user->unreadNotifications()->count()]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $paginator = $user->notifications()
            ->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->latest()
            ->paginate(ListQuery::perPage($request));

        return new JsonResponse([
            'data' => collect($paginator->items())->map(fn ($n): array => [
                'id' => $n->id,
                'type' => class_basename($n->type),
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601ZuluString(),
                'created_at' => $n->created_at?->toIso8601ZuluString(),
            ])->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
            'links' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl()],
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $updated = $user->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::data(['marked' => $updated]);
    }
}
