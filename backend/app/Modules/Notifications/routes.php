<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Support\Http\RequireAuthorization;
use Illuminate\Support\Facades\Route;

// Caller's own notifications only (documented RequireAuthorization exemption).
Route::middleware('authenticated')->withoutMiddleware(RequireAuthorization::class)->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::get('notifications/poll', [NotificationController::class, 'poll'])->name('notifications.poll');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification')->name('notifications.read');
    Route::post('notifications/{notification}/unread', [NotificationController::class, 'markUnread'])->whereUuid('notification')->name('notifications.unread');
});
