<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Identity\Http\Middleware\EnsureSessionIsValid;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    RateLimiter::clear('login');
});

it('logs in from the frontend origin, rotates the session and audits the login', function () {
    $user = User::factory()->create(['email' => 'staff@example.com']);

    $this->fromFrontend()->get('/sanctum/csrf-cookie')->assertNoContent();
    $before = session()->getId();

    $response = $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'STAFF@example.com ', 'password' => 'Password123']);

    $response->assertOk()->assertJsonPath('data.email', 'staff@example.com');
    expect(session()->getId())->not->toBe($before)
        ->and(session()->get(EnsureSessionIsValid::AUTHENTICATED_AT))->toBeInt()
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'auth.login')->where('actor_id', $user->id)->exists())->toBeTrue();
    $this->assertAuthenticatedAs($user, 'web');
});

it('rejects wrong passwords with a generic message and audits the failure without the password', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'wrong-password'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

    $log = AuditLog::query()->where('action', 'auth.login_failed')->sole();
    expect($log->changes)->toBe(['email' => 'staff@example.com'])
        ->and(json_encode($log->changes))->not->toContain('wrong-password');
    $this->assertGuest('web');
});

it('gives inactive accounts the same generic error as a wrong password', function () {
    User::factory()->inactive()->create(['email' => 'gone@example.com']);

    $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'gone@example.com', 'password' => 'Password123'])
        ->assertStatus(422)
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');

    $this->assertGuest('web');
});

it('refuses login from origins that are not stateful frontends', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->withHeaders(['Origin' => 'https://evil.example'])
        ->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Password123'])
        ->assertForbidden()
        ->assertJsonPath('code', 'stateful_origin_required');
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $_) {
        $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])->assertStatus(422);
    }

    $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'nope'])
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_requests')
        ->assertHeader('Retry-After');
});

it('returns the current user with permissions and data scope', function () {
    $user = $this->userWith(['branches.view']);

    $this->signedInAs($user)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.permissions', ['branches.view'])
        ->assertJsonPath('data.scope.all_branches', false)
        ->assertJsonMissingPath('data.password');
});

it('requires authentication for me', function () {
    $this->fromFrontend()->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('invalidates the session on logout', function () {
    $user = User::factory()->create();

    $this->signedInAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
    expect(AuditLog::query()->where('action', 'auth.logout')->exists())->toBeTrue();
});

it('logs out a user who is deactivated during their session', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_active' => false])->save();

    $this->signedInAs($user)->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'account_inactive');

    $this->assertGuest('web');
});

it('ends sessions older than the absolute lifetime', function () {
    config(['session.absolute_lifetime' => 60]);
    $user = User::factory()->create();

    $this->fromFrontend()->actingAs($user, 'web')
        ->withSession([EnsureSessionIsValid::AUTHENTICATED_AT => time() - 61 * 60])
        ->getJson('/api/v1/auth/me')
        ->assertUnauthorized();
});
