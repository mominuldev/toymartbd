<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Courier drivers and booking (Phase 6, DATABASE-DESIGN §3.6 / §4.2)
|--------------------------------------------------------------------------
|
| `drivers` maps a `courier_providers.code` to its CourierGateway driver (rule 5: a
| courier is added by writing a driver plus one line here). No merchant account exists
| yet (rule 33a): a provider row stays `verified = false` until its driver has been
| exercised against the live API, and production booking through it is refused.
|
| Credentials live encrypted on `courier_providers.credentials`, never here (rule 20).
|
*/

return [
    'drivers' => [
        'pathao' => App\Domain\Fulfilment\Drivers\Pathao\PathaoGateway::class,
        'steadfast' => App\Domain\Fulfilment\Drivers\Steadfast\SteadfastGateway::class,
    ],

    // Pathao (research agent3 §5). Credentials normally live encrypted on the
    // `courier_providers` row; these env values are a SANDBOX-only fallback (the
    // published test account works there — verified 2026-11-13). Live needs BOTH the row
    // (`settings.environment = production`) and PATHAO_SANDBOX=false.
    'pathao' => [
        'sandbox' => (bool) env('PATHAO_SANDBOX', true),
        'hosts' => [
            'sandbox' => env('PATHAO_BASE_URL', 'https://courier-api-sandbox.pathao.com'),
            'live' => 'https://api-hermes.pathao.com',
        ],
        'client_id' => env('PATHAO_CLIENT_ID'),
        'client_secret' => env('PATHAO_CLIENT_SECRET'),
        'username' => env('PATHAO_USERNAME'),
        'password' => env('PATHAO_PASSWORD'),
        'store_id' => env('PATHAO_STORE_ID'),
        'webhook_secret' => env('PATHAO_WEBHOOK_SECRET'),
        'timeout' => (int) env('PATHAO_TIMEOUT', 20),
        'connect_timeout' => 5,
        // Location sync: Pathao country 1 = Bangladesh. Milliseconds between calls when
        // the flat lists are unavailable and the sync walks city → zone → area.
        'country_id' => 1,
        'sync_delay_ms' => (int) env('PATHAO_SYNC_DELAY_MS', 250),
    ],

    // Production booking through an unverified provider is refused (rule 33a). Only
    // these environments are treated as production for that check.
    'production_environments' => ['production'],

    // Per-provider circuit breaker: after `failure_threshold` consecutive transport
    // failures the provider is "open" for `open_minutes`; booking jobs are released back
    // to the queue meanwhile instead of hammering a provider that is down. PLACEHOLDER
    // values — tune once real failure rates are known.
    'circuit_breaker' => [
        'failure_threshold' => (int) env('COURIER_BREAKER_THRESHOLD', 5),
        'open_minutes' => (int) env('COURIER_BREAKER_OPEN_MINUTES', 5),
    ],

    // Booking job retry policy for transport failures (the job is released, not failed).
    'booking' => [
        'tries' => (int) env('COURIER_BOOKING_TRIES', 5),
        'backoff_seconds' => [30, 120, 300, 900],
    ],

    // Steadfast (docs/_research/agent3-appbyte-and-couriers.md §6). There is NO sandbox
    // (rule 33a): the driver is exercised only against hand-authored fixtures until a
    // merchant account exists. portal.steadfast.com.bd is dead — keep portal.packzy.com.
    'steadfast' => [
        'base_url' => env('STEADFAST_BASE_URL', 'https://portal.packzy.com/api/v1'),
        'timeout_seconds' => (int) env('STEADFAST_TIMEOUT', 15),
        // Bulk create caps a request at 500 orders (the guide, verbatim).
        'bulk_chunk_size' => 500,
        // How long a stored fraud_check answer is reused before asking again.
        'fraud_lookup_ttl_hours' => (int) env('STEADFAST_FRAUD_LOOKUP_TTL_HOURS', 24),
    ],

    // Status ingestion (P6): the reconciliation poll (`toymart:couriers:poll`, every 15
    // minutes) re-queries every shipment still with a courier that has had no update for
    // `stale_minutes`, at most `max_per_run` per run, in jobs of `chunk` on the `courier`
    // queue. PLACEHOLDER values — tune against real provider rate limits.
    'status_poll' => [
        'stale_minutes' => (int) env('COURIER_POLL_STALE_MINUTES', 60),
        'max_per_run' => (int) env('COURIER_POLL_MAX_PER_RUN', 300),
        'chunk' => (int) env('COURIER_POLL_CHUNK', 25),
    ],

    // Shipment numbers: SHP-YY-NNNNNN, sequenced per Dhaka year.
    'shipment_number_prefix' => 'SHP',
];
