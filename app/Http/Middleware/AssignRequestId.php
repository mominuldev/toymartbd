<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Logging\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Correlation id for one request (SYSTEM-ARCHITECTURE.md §11, P3-CI-04).
 *
 * The edge (or the storefront's Route Handler) sends `X-Request-Id`; we keep it only
 * when it looks like an id — 8–128 of `[A-Za-z0-9._-]` — so a client cannot inject
 * newlines or markup into every log line. Otherwise a fresh UUIDv7 is minted.
 *
 * The id goes into Laravel's Context, which (a) appends it to every log record's
 * `extra` and (b) is dehydrated into every job dispatched during the request and
 * hydrated again on the worker — so the worker's log lines and Sentry events carry
 * the same id. It is echoed on the response for support to quote.
 */
final class AssignRequestId
{
    public const string HEADER = RequestId::HEADER;

    public const string CONTEXT_KEY = RequestId::CONTEXT_KEY;

    private const string PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::accept($request->headers->get(self::HEADER)) ?? (string) Str::uuid7();

        // Downstream code reading the header sees the value we actually use.
        $request->headers->set(self::HEADER, $id);
        Context::add(self::CONTEXT_KEY, $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function accept(?string $candidate): ?string
    {
        return $candidate !== null && preg_match(self::PATTERN, $candidate) === 1 ? $candidate : null;
    }
}
