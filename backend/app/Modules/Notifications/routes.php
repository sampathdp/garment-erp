<?php

declare(strict_types=1);

use App\Modules\Notifications\Http\Controllers\NotificationController;
use App\Support\Http\RequireAuthorization;
use Illuminate\Support\Facades\Route;

// Caller's own notifications only (documented RequireAuthorization exemption).
Route::middleware('authenticated')->withoutMiddleware(RequireAuthorization::class)->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
});
