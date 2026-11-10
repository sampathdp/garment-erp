<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;

/**
 * Ends every existing session of the user (all devices) on their next request, independently
 * of the session driver: EnsureSessionIsValid compares the session's stored version with
 * users.session_version. The remember-me token is rotated as well.
 *
 * Call inside the caller's transaction.
 */
final class RevokeUserSessions
{
    public function handle(User $user): void
    {
        $user->forceFill([
            'session_version' => $user->session_version + 1,
            'remember_token' => Str::random(60),
        ])->save();
    }
}
