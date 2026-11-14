<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response security headers on everything Laravel serves (P9 security review,
 * docs/audits/SECURITY-REVIEW.md). Three shapes:
 *
 * - **Every response**: nosniff, a referrer policy, a permissions policy, HSTS over HTTPS,
 *   and X-Frame-Options DENY (nothing Laravel serves is meant to be framed).
 * - **The JSON API** (`api/v1/*`): `default-src 'none'; frame-ancestors 'none'` — a JSON or
 *   problem body opened directly in a browser can never run or load anything.
 * - **The admin shell** (`/admin/*`): a nonce CSP. The one inline script (theme before first
 *   paint) and Vite's tags carry the per-request nonce; scripts come from this origin only,
 *   so an injected <script> or event handler cannot run. Styles allow 'unsafe-inline'
 *   (React style props) — CSS cannot run script. Fonts from fonts.bunny.net; images from
 *   anywhere over https (media CDN, OG previews); the storefront origin may be framed for
 *   the homepage builder's live canvas. While `npm run dev` is hot, the Vite dev server's
 *   origin (and its websocket) is added.
 *
 * Responses that already set a header keep theirs (e.g. Laravel's signed file serving).
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->is('admin', 'admin/*');
        $nonce = $admin ? Vite::useCspNonce() : null;

        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        ];

        if ($request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        if ($request->is('api/v1/*', 'api/v1')) {
            $defaults['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";
        } elseif ($nonce !== null) {
            $defaults['Content-Security-Policy'] = self::adminPolicy($nonce);
        }

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        return $response;
    }

    public static function adminPolicy(string $nonce): string
    {
        $hot = self::hotOrigins();
        $storefront = self::origin(config('services.storefront.url'));
        $local = ! app()->isProduction();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$hot['http']],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', ...$hot['http']],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net', ...$hot['http']],
            'img-src' => ["'self'", 'data:', 'blob:', 'https:', ...($local ? ['http:'] : [])],
            // + the admin's Sentry ingest origin, only when SENTRY_ADMIN_DSN is set (P10).
            'connect-src' => ["'self'", ...$hot['http'], ...$hot['ws'], ...(($sentry = self::origin(config('monitoring.sentry_admin.dsn'))) !== null ? [$sentry] : [])],
            'frame-src' => ["'self'", ...($storefront !== null ? [$storefront] : [])],
            'frame-ancestors' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        return implode('; ', array_map(
            static fn (string $name, array $sources): string => $name.' '.implode(' ', array_unique($sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /** @return array{http: list<string>, ws: list<string>} */
    private static function hotOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $origin = self::origin(trim((string) @file_get_contents(Vite::hotFile())));
        if ($origin === null) {
            return ['http' => [], 'ws' => []];
        }

        return ['http' => [$origin], 'ws' => [(string) preg_replace('/^http/', 'ws', $origin)]];
    }

    private static function origin(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return $parts['scheme'].'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
