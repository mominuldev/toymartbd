<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server-side tracking (PRD K-01..K-03, SYSTEM-ARCHITECTURE §8 "Server-side tracking")
|--------------------------------------------------------------------------
|
| Every value below is the ENV FALLBACK only. Admin → Integrations → Tracking saves
| the same keys to `settings` (group `tracking`, keys `tracking.*`) and the saved value
| wins — see App\Domain\Tracking\Services\TrackingSettings. Secrets (the GA4 API secret,
| the Meta access token) are stored there with `is_encrypted` (rule 20). Never commit
| real values here.
|
| Nothing is sent unless server_side_enabled is on AND the destination is enabled AND
| its credentials are present AND the visitor's consent allows it (DispatchPolicy).
|
*/

return [

    // Master switch for every server-side send (GA4 MP + Meta CAPI).
    'server_side_enabled' => env('TRACKING_SERVER_SIDE_ENABLED', false),

    'ga4' => [
        'enabled' => env('TRACKING_GA4_ENABLED', false),
        'measurement_id' => env('TRACKING_GA4_MEASUREMENT_ID'),
        'api_secret' => env('TRACKING_GA4_API_SECRET'),
        // https://www.google-analytics.com, or https://region1.google-analytics.com to
        // collect in the EU (https://developers.google.com/analytics/devguides/collection/protocol/ga4/reference).
        'base_url' => env('TRACKING_GA4_BASE_URL', 'https://www.google-analytics.com'),
        'timeout' => 10,
    ],

    'meta' => [
        'enabled' => env('TRACKING_META_ENABLED', false),
        'pixel_id' => env('TRACKING_META_PIXEL_ID'),
        'access_token' => env('TRACKING_META_ACCESS_TOKEN'),
        'test_event_code' => env('TRACKING_META_TEST_EVENT_CODE'),
        // Graph API version the Meta driver calls. Operational, config-only (never in the
        // admin). v27.0 = current Graph/Marketing API version, introduced 2026-11-04
        // (https://developers.facebook.com/docs/graph-api/changelog, read 2026-11-13).
        'graph_version' => env('TRACKING_META_GRAPH_VERSION', 'v27.0'),
        'base_url' => env('TRACKING_META_BASE_URL', 'https://graph.facebook.com'),
        'timeout' => 10,
    ],

    'gtm_container_id' => env('TRACKING_GTM_CONTAINER_ID'),

    // order_placed | order_delivered — which order event fires `purchase` (exactly one).
    'purchase_trigger' => env('TRACKING_PURCHASE_TRIGGER', 'order_placed'),

    // opt_in (nothing fires before the visitor accepts) | notice (fires unless refused).
    // Default opt_in: BD guidance asks for prior consent (docs/_research/agent4 §7.10).
    'consent_mode' => env('TRACKING_CONSENT_MODE', 'opt_in'),
    'consent_policy_version' => env('TRACKING_CONSENT_POLICY_VERSION', '2026-11'),

    // Queue + staleness (SYSTEM-ARCHITECTURE §7): 3 tries, never retried past 1 h.
    'queue' => env('TRACKING_QUEUE', 'tracking'),
    'stale_after_seconds' => 3600,

    // Delay before a `purchase` from OrderPlaced is dispatched, so the checkout's tracking
    // context (written just after PlaceOrder commits) is on disk when the job reads it.
    'purchase_dispatch_delay_seconds' => 20,

    // order_tracking_contexts: IP / user agent / click ids are personal data. After this
    // many days `toymart:tracking:prune-contexts` blanks them (the consent record stays).
    'context_retention_days' => 90,

    // POST /storefront/tracking/events, per shopper IP (client.ip) per minute.
    'ingest_per_minute' => 120,

    // Destination key → driver class (rule 5). Resolved through the container; a class
    // that does not exist yet is simply not registered.
    'destinations' => [
        'ga4' => 'App\\Domain\\Tracking\\Destinations\\Ga4MeasurementProtocolDestination',
        'meta' => 'App\\Domain\\Tracking\\Destinations\\MetaConversionsApiDestination',
    ],
];
