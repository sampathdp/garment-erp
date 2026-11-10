<?php

declare(strict_types=1);

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Rejects oversized non-file request bodies before they are parsed.
 * File uploads use their own per-category limits (DocumentCategory::maxBytes()).
 */
final class LimitRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $isMultipart = str_starts_with((string) $request->headers->get('Content-Type'), 'multipart/form-data');
        $limit = (int) config($isMultipart ? 'api.max_upload_body_bytes' : 'api.max_json_body_bytes');
        $length = (int) $request->headers->get('Content-Length', '0');

        if ($length > $limit || (! $isMultipart && strlen((string) $request->getContent()) > $limit)) {
            throw new HttpException(413, 'Request body too large.');
        }

        return $next($request);
    }
}
