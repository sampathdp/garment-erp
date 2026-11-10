<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

it('serves the health endpoint with security headers and a request id', function () {
    $response = $this->getJson('/api/v1/health');

    $response->assertOk()
        ->assertExactJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'cache' => 'ok']])
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(Str::isUuid($response->headers->get('X-Request-Id')))->toBeTrue()
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
});

it('echoes a valid client request id and replaces an invalid one', function () {
    $valid = (string) Str::uuid();

    $this->getJson('/api/v1/health', ['X-Request-Id' => $valid])->assertHeader('X-Request-Id', $valid);

    $replaced = $this->getJson('/api/v1/health', ['X-Request-Id' => "evil\r\ninjected"])->headers->get('X-Request-Id');
    expect(Str::isUuid($replaced))->toBeTrue();
});

it('returns the standard envelope for unknown routes', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()->assertJsonPath('code', 'not_found')->assertJsonPath('message', 'Resource not found.');
    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('never leaks internal exception messages when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware('api')->get('/api/v1/_test/boom', fn () => throw new RuntimeException('SQLSTATE secret connection string'));

    $response = $this->getJson('/api/v1/_test/boom');

    $response->assertStatus(500)->assertExactJsonStructure(['message', 'code', 'request_id'])->assertJsonPath('message', 'Server error.');
    expect($response->getContent())->not->toContain('secret')->not->toContain('SQLSTATE')->not->toContain('trace');
});

it('returns 422 with field errors in the envelope', function () {
    $this->fromFrontend()->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['email', 'password']);
});

it('rejects oversized JSON bodies with 413', function () {
    config(['api.max_json_body_bytes' => 1024]);

    $this->fromFrontend()->postJson('/api/v1/auth/login', ['email' => 'a@b.co', 'password' => str_repeat('x', 2000)])
        ->assertStatus(413)
        ->assertJsonPath('code', 'payload_too_large');
});

it('allows CORS only for the configured frontend origin, with credentials', function () {
    $allowed = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    expect($allowed->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:3000')
        ->and($allowed->headers->get('Access-Control-Allow-Credentials'))->toBe('true');

    $denied = $this->call('OPTIONS', '/api/v1/health', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);
    // A single configured origin is always echoed; the browser blocks the mismatch.
    expect($denied->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')->not->toBe('*');
});
