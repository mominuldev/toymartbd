<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Idempotency-Key (P3-API-03, CLAUDE.md rule 17)
|--------------------------------------------------------------------------
|
| Used by App\Http\Middleware\EnsureIdempotency (alias `idempotent`). Records
| live in the cache store, not a table: docs/SYSTEM-ARCHITECTURE.md §5 says
| "stored … for 24h in Redis", and docs/DATABASE-DESIGN.md defines no
| idempotency_keys table. The store must support atomic locks (redis, database,
| array, file — not `null`).
|
*/

return [
    // Cache store name; null = the default store (CACHE_STORE, Redis in production).
    'store' => env('IDEMPOTENCY_STORE'),

    // How long a completed response is replayable for.
    'ttl_seconds' => (int) env('IDEMPOTENCY_TTL_SECONDS', 86400),

    // In-flight lock lifetime. Must exceed the slowest marked endpoint, or a second
    // request could start while the first is still running.
    'lock_seconds' => (int) env('IDEMPOTENCY_LOCK_SECONDS', 60),
];
