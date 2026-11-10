<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Http\Middleware\EnsureSessionIsValid;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    Cache::flush();
});

/** A session stamped with an older session version, for a user freshly loaded from the database. */
function sessionIssuedWithVersion($test, User $fresh, int $version)
{
    app('auth')->forgetGuards();

    return $test->fromFrontend()->actingAs($fresh, 'web')
        ->withSession([EnsureSessionIsValid::AUTHENTICATED_AT => time(), EnsureSessionIsValid::SESSION_VERSION => $version]);
}

describe('forgot password', function () {
    it('answers identically for known, unknown and inactive accounts and mails only active ones', function () {
        Notification::fake();
        $active = User::factory()->create(['email' => 'active@example.com']);
        $inactive = User::factory()->inactive()->create(['email' => 'inactive@example.com']);

        $responses = collect(['active@example.com', 'nobody@example.com', 'inactive@example.com'])
            ->map(fn (string $email) => $this->fromFrontend()->postJson('/api/v1/auth/forgot-password', ['email' => $email]));

        $responses->each(fn ($r) => $r->assertStatus(202)->assertJsonPath('message', 'If an active account exists for this email, a reset link has been sent.'));
        Notification::assertSentTo($active, ResetPasswordNotification::class, fn ($n) => ! $n->invitation);
        Notification::assertNotSentTo($inactive, ResetPasswordNotification::class);
        Notification::assertCount(1);
    });

    it('stores the reset token hashed and links to the frontend reset page', function () {
        Notification::fake();
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/forgot-password', ['email' => 'STAFF@example.com'])->assertStatus(202);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use ($user) {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $mail = $n->toMail($user);

            return $stored !== $n->token && Hash::check($n->token, $stored)
                && str_starts_with((string) $mail->actionUrl, 'http://localhost:3000/reset-password/?token=');
        });
    });

    it('is rate limited per IP', function () {
        foreach (range(1, 5) as $_) {
            $this->fromFrontend()->postJson('/api/v1/auth/forgot-password', ['email' => 'x@example.com'])->assertStatus(202);
        }

        $this->fromFrontend()->postJson('/api/v1/auth/forgot-password', ['email' => 'x@example.com'])->assertStatus(429);
    });
});

describe('reset password', function () {
    it('resets with a valid token, ends existing sessions and accepts the token only once', function () {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        $token = Password::broker()->createToken($user);
        $payload = ['email' => 'staff@example.com', 'token' => $token, 'password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026'];

        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', $payload)->assertOk();

        $fresh = $user->fresh();
        expect(Hash::check('NewSecret2026', $fresh->password))->toBeTrue()
            ->and($fresh->session_version)->toBe(2)
            ->and($fresh->password_changed_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'auth.password_reset')->where('subject_id', $user->id)->exists())->toBeTrue();

        // An old session (issued with version 1) is now rejected.
        sessionIssuedWithVersion($this, $fresh, 1)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Single use.
        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', $payload)
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    });

    it('rejects wrong, foreign and expired tokens with the same message', function () {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        $other = User::factory()->create(['email' => 'other@example.com']);
        $otherToken = Password::broker()->createToken($other);
        $base = ['email' => 'staff@example.com', 'password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026'];

        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', $base + ['token' => 'not-a-token'])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', $base + ['token' => $otherToken])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

        $token = Password::broker()->createToken($user);
        $this->travel(61)->minutes();
        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', $base + ['token' => $token])->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

        expect(Hash::check('Password123', $user->fresh()->password))->toBeTrue();
    });

    it('enforces the password policy', function () {
        $user = User::factory()->create(['email' => 'staff@example.com']);
        $token = Password::broker()->createToken($user);

        foreach (['short1', 'onlyletterslong', '1234567890123'] as $weak) {
            $this->fromFrontend()->postJson('/api/v1/auth/reset-password', ['email' => 'staff@example.com', 'token' => $token, 'password' => $weak, 'password_confirmation' => $weak])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', ['email' => 'staff@example.com', 'token' => $token, 'password' => 'NewSecret2026', 'password_confirmation' => 'Different2026'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('checks breached passwords when enabled', function () {
        config(['api.passwords.check_breached' => true]);
        // HIBP k-anonymity range response containing the SHA-1 suffix of "NewSecret2026".
        $sha1 = strtoupper(sha1('NewSecret2026'));
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($sha1, 5).':42', 200)]);
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/reset-password', ['email' => 'staff@example.com', 'token' => Password::broker()->createToken($user), 'password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    });
});

describe('change password', function () {
    it('requires the current password', function () {
        $user = User::factory()->create();

        $this->signedInAs($user)->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        expect(AuditLog::query()->where('action', 'auth.password_change_failed')->exists())->toBeTrue();
    });

    it('changes the password, keeps this session and ends the other sessions', function () {
        $user = User::factory()->create();

        $this->signedInAs($user)->putJson('/api/v1/auth/password', ['current_password' => 'Password123', 'password' => 'NewSecret2026', 'password_confirmation' => 'NewSecret2026'])
            ->assertNoContent();

        expect(Hash::check('NewSecret2026', $user->fresh()->password))->toBeTrue()
            ->and(session()->get(EnsureSessionIsValid::SESSION_VERSION))->toBe(2);

        // This session continues...
        $this->getJson('/api/v1/auth/me')->assertOk();
        // ...a session issued before the change (another device) does not.
        sessionIssuedWithVersion($this, $user->fresh(), 1)->getJson('/api/v1/auth/me')->assertUnauthorized();
    });

    it('requires authentication', function () {
        $this->fromFrontend()->putJson('/api/v1/auth/password', [])->assertUnauthorized();
    });
});

describe('profile', function () {
    it('updates own name and phone (normalised to E.164) but never email or roles', function () {
        $user = User::factory()->create(['email' => 'me@example.com']);

        $this->signedInAs($user)->patchJson('/api/v1/auth/profile', ['name' => 'New Name', 'phone' => '077 123 4567', 'email' => 'evil@example.com', 'roles' => ['super_admin']])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.phone', '+94771234567')
            ->assertJsonPath('data.email', 'me@example.com')
            ->assertJsonPath('data.roles', []);
    });

    it('validates input', function () {
        $this->signedInAs(User::factory()->create())->patchJson('/api/v1/auth/profile', ['name' => '', 'phone' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'phone']);
    });
});

describe('sessions and lockout', function () {
    it('locks an email+IP out with growing delays after repeated failures', function () {
        // Isolate the lockout from the per-minute throttle (covered in AuthenticationTest).
        config(['api.rate_limits.login_per_email' => 1000, 'api.rate_limits.login_per_ip' => 1000]);
        User::factory()->create(['email' => 'staff@example.com']);
        $attempt = fn (string $password) => $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => $password]);

        foreach (range(1, 5) as $_) {
            $attempt('wrong')->assertStatus(422);
        }
        // Locked: even the right password is refused for now.
        $attempt('Password123')->assertStatus(429)->assertJsonPath('code', 'login_locked');

        $this->travel(61)->seconds();
        foreach (range(1, 5) as $_) {
            $attempt('wrong')->assertStatus(422);
        }
        $this->travel(61)->seconds(); // the second lockout lasts 120 s
        $attempt('Password123')->assertStatus(429);

        $this->travel(60)->seconds();
        $attempt('Password123')->assertOk();
    });

    it('stores the session version at login and rejects sessions after revocation', function () {
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Password123'])->assertOk();
        expect(session()->get(EnsureSessionIsValid::SESSION_VERSION))->toBe(1);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $user->forceFill(['session_version' => 2])->save();
        app('auth')->forgetGuards(); // a real request loads the user from the database

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertGuest('web');
    });

    it('rejects sessions without a stored version', function () {
        $user = User::factory()->create();

        $this->fromFrontend()->actingAs($user, 'web')->withSession([EnsureSessionIsValid::AUTHENTICATED_AT => time()])
            ->getJson('/api/v1/auth/me')->assertUnauthorized();
    });

    it('never authenticates soft-deleted accounts', function () {
        $user = User::factory()->create(['email' => 'gone@example.com']);
        $user->delete();

        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'gone@example.com', 'password' => 'Password123'])->assertStatus(422);
    });
});
