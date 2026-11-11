<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
| Sanctum (P3-API-05, docs/SYSTEM-ARCHITECTURE.md §5 "Authentication").
|
| - Admin SPA: cookie session. Only requests whose Origin/Referer is one of the
|   `stateful` domains get the session + CSRF stack, and only on the admin route
|   group (bootstrap/app.php). The SPA is served by this app at /admin, so it is
|   same-origin: localhost:8000 locally, admin.toymartbd.com in production.
| - Storefront server: one personal access token with `storefront:read`, minted by
|   `php artisan toymart:storefront-token`. Never reaches the browser (rule 28).
| - Customer tokens: Phase 4 (the customers table does not exist yet).
*/

return [

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    // Session guard(s) Sanctum checks before falling back to a bearer token.
    'guard' => ['web'],

    // Minutes. Null: personal access tokens do not expire; the storefront token is
    // rotated by re-running the artisan command with --rotate.
    'expiration' => env('SANCTUM_EXPIRATION') !== null ? (int) env('SANCTUM_EXPIRATION') : null,

    // A fixed prefix lets secret scanners (GitHub, gitleaks) recognise a leaked token.
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
