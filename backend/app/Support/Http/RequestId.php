<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request ID (accepts a client X-Request-Id only if it is a valid UUID),
 * adds it to every log line, and returns it in the X-Request-Id response header.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = '_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(?Request $request = null): ?string
    {
        $request ??= request();
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}
