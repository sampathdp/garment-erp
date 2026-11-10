<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Http\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Centralised JSON error envelope for /api/* (API_STANDARDS.md §4):
 *   { "message": string, "code": string, "errors"?: {field: [msg]}, "request_id": string }
 *
 * Internal exception messages and stack traces are never returned unless APP_DEBUG=true
 * (which is forbidden outside local development; see app:security-check).
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson()
        );

        $exceptions->dontReport([ApiException::class]);

        $exceptions->render(static function (Throwable $e, Request $request): ?JsonResponse {
            if (! ($request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson())) {
                return null;
            }

            return self::toResponse($e, $request);
        });
    }

    public static function toResponse(Throwable $e, Request $request): JsonResponse
    {
        // The framework wraps policy denials in HttpExceptions; the original keeps the status and code.
        $denial = $e instanceof AuthorizationException ? $e : ($e->getPrevious() instanceof AuthorizationException ? $e->getPrevious() : null);

        [$status, $code, $message, $errors] = match (true) {
            $e instanceof ApiException => [$e->status, $e->errorCode, $e->getMessage(), $e->errors],
            $e instanceof ValidationException => [422, 'validation_failed', 'The given data was invalid.', $e->errors()],
            $e instanceof AuthenticationException => [401, 'unauthenticated', 'Unauthenticated.', null],
            $denial !== null => self::denial($denial),
            $e instanceof AccessDeniedHttpException => [403, 'forbidden', 'This action is unauthorized.', null],
            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => [404, 'not_found', 'Resource not found.', null],
            $e instanceof TokenMismatchException => [419, 'csrf_token_mismatch', 'CSRF token mismatch. Refresh and try again.', null],
            $e instanceof ThrottleRequestsException => [429, 'too_many_requests', 'Too many requests. Please slow down.', null],
            $e instanceof HttpExceptionInterface => [$e->getStatusCode(), self::httpCode($e->getStatusCode()), self::safeHttpMessage($e), null],
            default => [500, 'server_error', 'Server error.', null],
        };

        $body = ['message' => $message, 'code' => $code];
        if ($errors !== null) {
            $body['errors'] = $errors;
        }
        $body['request_id'] = RequestId::current($request);

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile().':'.$e->getLine()];
        }

        $headers = $e instanceof HttpExceptionInterface ? array_intersect_key($e->getHeaders(), array_flip(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset', 'Allow'])) : [];

        return new JsonResponse($body, $status, $headers);
    }

    /**
     * Policy denials: out of scope → 404 (never reveals existence); state conflicts → 409;
     * otherwise 403. Policies may set a stable code and a safe message (Response::deny($msg, $code)).
     *
     * @return array{0: int, 1: string, 2: string, 3: null}
     */
    private static function denial(AuthorizationException $e): array
    {
        $status = $e->status() ?? 403;
        if ($status === 404) {
            return [404, 'not_found', 'Resource not found.', null];
        }

        // response() is null when the exception was thrown without a policy Response.
        /** @var Response|null $response */
        $response = $e->response();
        $code = $response?->code();
        $message = (string) ($response?->message() ?? $e->getMessage());

        return [
            $status,
            is_string($code) && $code !== '' ? $code : self::httpCode($status),
            $message !== '' ? mb_substr($message, 0, 300) : 'This action is unauthorized.',
            null,
        ];
    }

    private static function httpCode(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            419 => 'csrf_token_mismatch',
            422 => 'unprocessable',
            429 => 'too_many_requests',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'server_error' : 'http_error',
        };
    }

    /** Only messages we set ourselves (4xx) are safe to show; 5xx are always generic. */
    private static function safeHttpMessage(HttpExceptionInterface $e): string
    {
        $status = $e->getStatusCode();
        if ($status >= 500) {
            return $status === 503 ? 'Service temporarily unavailable.' : 'Server error.';
        }

        $message = $e->getMessage();

        return $message !== '' ? mb_substr($message, 0, 200) : 'Request could not be processed.';
    }
}
