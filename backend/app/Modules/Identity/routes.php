<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PageMemberController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\UserController;
use App\Support\Http\RequireAuthorization;
use Illuminate\Support\Facades\Route;

// Public (throttled). CSRF: GET /sanctum/csrf-cookie first, then send X-XSRF-TOKEN.
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('auth.forgot-password');
Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('auth.reset-password');

// Own-session / own-account endpoints: no record-level authorization applies (documented exemption).
Route::middleware('authenticated')->withoutMiddleware(RequireAuthorization::class)->group(function (): void {
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::patch('auth/profile', [AuthController::class, 'updateProfile'])->middleware('throttle:sensitive')->name('auth.profile');
    Route::put('auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:sensitive')->name('auth.password');
});

Route::middleware('authenticated')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->middleware('throttle:sensitive')->name('users.store');
    Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
    Route::put('users/{user}/status', [UserController::class, 'status'])->whereNumber('user')->name('users.status');
    Route::put('users/{user}/roles', [UserController::class, 'roles'])->whereNumber('user')->name('users.roles');
    Route::put('users/{user}/assignments', [UserController::class, 'assignments'])->whereNumber('user')->name('users.assignments');
    Route::post('users/{user}/password-reset', [UserController::class, 'sendPasswordReset'])->whereNumber('user')->middleware('throttle:sensitive')->name('users.password-reset');
    Route::put('users/{user}/password', [UserController::class, 'setPassword'])->whereNumber('user')->middleware('throttle:sensitive')->name('users.password');
    Route::get('users/{user}/activity', [UserController::class, 'activity'])->whereNumber('user')->name('users.activity');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}', [RoleController::class, 'show'])->whereNumber('role')->name('roles.show');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');

    Route::get('pages/{page}/members', [PageMemberController::class, 'index'])->whereNumber('page')->name('pages.members.index');
    Route::put('pages/{page}/members', [PageMemberController::class, 'update'])->whereNumber('page')->name('pages.members.update');
});
