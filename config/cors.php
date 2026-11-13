<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| CORS — closed by default (P9 security review)
|--------------------------------------------------------------------------
|
| No browser ever calls this API cross-origin: the admin SPA is served by
| Laravel from the API's own origin, the storefront reaches the API from its
| Next.js server only (CLAUDE.md rule 28), and webhooks are server-to-server.
| Laravel's framework default is `allowed_origins: ['*']`, which would let any
| site read any public API response from a visitor's browser. Here no origin
| is allowed unless CORS_ALLOWED_ORIGINS lists it (comma-separated, exact
| origins only). Credentials are never allowed cross-origin.
|
*/

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN', 'Idempotency-Key', 'Accept-Language'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
