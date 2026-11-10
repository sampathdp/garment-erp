<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Laravel skips CSRF checks while running unit tests. This test re-enables real
 * verification to prove stateful SPA requests without a valid token are rejected.
 */
beforeEach(function () {
    $this->app->instance(ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends ValidateCsrfToken
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
});

it('rejects state-changing stateful requests without a CSRF token', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Password123'])
        ->assertStatus(419)
        ->assertJsonPath('code', 'csrf_token_mismatch');
});

it('accepts stateful requests that send the XSRF token from the csrf-cookie endpoint', function () {
    User::factory()->create(['email' => 'staff@example.com']);

    $cookie = collect($this->fromFrontend()->get('/sanctum/csrf-cookie')->headers->getCookies())
        ->first(fn ($c) => $c->getName() === 'XSRF-TOKEN');
    expect($cookie)->not->toBeNull()->and($cookie->isHttpOnly())->toBeFalse();

    $this->fromFrontend()
        ->withHeader('X-XSRF-TOKEN', urldecode($cookie->getValue()))
        ->withUnencryptedCookie('XSRF-TOKEN', urldecode($cookie->getValue()))
        ->postJson('/api/v1/auth/login', ['email' => 'staff@example.com', 'password' => 'Password123'])
        ->assertOk();
});
