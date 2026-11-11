<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Support\Http\ApiResponse;
use App\Support\Http\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The caller's own notifications (history, read/unread, mark read, mark all read, polling).
 * Shared hosting has no WebSocket server (Reverb needs a long-running process), so the frontend
 * polls `GET /notifications/poll` (cheap, indexed) for near-real-time updates.
 * Queries are always constrained to the authenticated user, so no record-level policy applies.
 */
final class NotificationController
{
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::data(['unread' => $this->user($request)->unreadNotifications()->count()]);
    }

    /** History (newest first). ?filter[unread]=1 for unread only. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['filter.unread' => ['sometimes', 'boolean']]);
        $paginator = $this->user($request)->notifications()
            ->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->when($request->boolean('filter.unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest()->orderByDesc('id')
            ->paginate(ListQuery::perPage($request));

        return new JsonResponse([
            'data' => collect($paginator->items())->map(fn (DatabaseNotification $n): array => self::row($n))->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
            'links' => ['next' => $paginator->nextPageUrl(), 'prev' => $paginator->previousPageUrl()],
        ]);
    }

    /**
     * Polling endpoint: unread count + notifications created after `since` (ISO 8601, max 20).
     * Returns `server_time` to use as the next `since`, so clients never miss or repeat rows.
     */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate(['since' => ['sometimes', 'nullable', 'date']]);
        $user = $this->user($request);
        $now = now();
        $since = isset($data['since']) ? Carbon::parse((string) $data['since'])->utc() : null;
        $new = $since === null ? collect() : $user->notifications()->select(['id', 'type', 'data', 'read_at', 'created_at'])
            ->where('created_at', '>', $since)->where('created_at', '<=', $now)->latest()->limit(20)->get();

        return ApiResponse::data([
            'unread' => $user->unreadNotifications()->count(),
            'new' => $new->map(fn (DatabaseNotification $n): array => self::row($n))->values()->all(),
            'server_time' => $now->toIso8601ZuluString(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        return $this->setRead($request, $notification, true);
    }

    public function markUnread(Request $request, string $notification): JsonResponse
    {
        return $this->setRead($request, $notification, false);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::data(['marked' => $updated]);
    }

    private function setRead(Request $request, string $id, bool $read): JsonResponse
    {
        // Only the caller's own notification: another user's id is a 404.
        $row = $this->user($request)->notifications()->whereKey($id)->first();
        abort_if(! $row instanceof DatabaseNotification, 404);
        $row->forceFill(['read_at' => $read ? ($row->read_at ?? now()) : null])->save();

        return ApiResponse::data(self::row($row));
    }

    /** @return array<string, mixed> */
    private static function row(DatabaseNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => class_basename($n->type),
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at?->toIso8601ZuluString(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
