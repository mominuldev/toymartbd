<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Monitoring (P10, SYSTEM-ARCHITECTURE.md §11) — docs/ops/observability.md
|--------------------------------------------------------------------------
|
| Health endpoint, business alerts and the synthetic add-to-cart check.
|
| Every threshold here is a PLACEHOLDER chosen before launch traffic exists. The owner can
| override any of them without a deploy through a `settings` row whose key is
| `monitoring.<path>` (group `system`), e.g. `monitoring.alerts.no_orders.hours` = 4 —
| App\Domain\Monitoring\Services\MonitoringSettings reads the setting first, then this file.
|
*/

$list = static fn (?string $csv): array => array_values(array_filter(array_map('trim', explode(',', (string) $csv)), static fn (string $v): bool => $v !== ''));

return [

    'health' => [
        // Seconds a computed report is reused, so a burst of uptime checks cannot load the DB.
        'cache_seconds' => (int) env('MONITORING_HEALTH_CACHE_SECONDS', 10),

        // Send this as `X-Health-Token` to get the per-check detail. Without it (or when this is
        // empty) the endpoint answers with the overall status only. Never a real value in git.
        'detail_token' => env('MONITORING_HEALTH_TOKEN'),

        // When true, a heartbeat that has NEVER been recorded (scheduler, backup) counts as a
        // failure. Off locally and in CI, where no scheduler runs; on in staging/production.
        'expect_heartbeats' => (bool) env('MONITORING_EXPECT_HEARTBEATS', in_array(env('APP_ENV'), ['production', 'staging'], true)),

        // The scheduler beats every minute; older than this = the cron entry is not running.
        'scheduler_stale_minutes' => (int) env('MONITORING_SCHEDULER_STALE_MINUTES', 5),

        // Queue depth, courier silence and backup age use the alert thresholds below, so the
        // endpoint and the alerts can never disagree.
    ],

    'alerts' => [
        // Scheduled checks run every five minutes. Off by default outside production so a
        // developer machine never mails the owner; `toymart:alerts:test` works regardless.
        'enabled' => (bool) env('MONITORING_ALERTS_ENABLED', env('APP_ENV') === 'production'),

        // A still-firing alert is re-sent after this many minutes; a resolved one sends one
        // "resolved" notice. Between those, a re-run is a no-op (de-duplication).
        'renotify_minutes' => (int) env('MONITORING_ALERT_RENOTIFY_MINUTES', 240),

        // The owner channel. Empty email list = every active super admin. Phones are E.164
        // or 01XXXXXXXXX; empty = no SMS (an SMS costs money; e-mail does not).
        'channels' => [
            'mail' => $list(env('MONITORING_ALERT_EMAILS')),
            'sms' => $list(env('MONITORING_ALERT_PHONES')),
            // Alerts at or above this severity also go by SMS: `warning` or `critical`.
            'sms_min_severity' => env('MONITORING_ALERT_SMS_MIN_SEVERITY', 'critical'),
        ],

        // Zero orders over this many BUSINESS hours (Dhaka) is suspicious (§11: 3 daytime hours).
        'no_orders' => [
            'hours' => 3,
            'business_start' => '09:00',
            'business_end' => '23:00',
        ],

        // Courier booking failures in the window: fires when failures >= min_failures AND the
        // failure rate is above rate_percent (§11: >10% in 15 min).
        'courier_booking_failures' => [
            'window_minutes' => 15,
            'min_failures' => 3,
            'rate_percent' => 10,
        ],

        // Failed queue jobs in the last hour (§11: >20/h).
        'failed_jobs' => [
            'window_minutes' => 60,
            'threshold' => 20,
        ],

        // No successful courier API answer (poll or booking) while shipments are with a courier.
        'courier_poll_silence' => [
            'minutes' => 90,
        ],

        // No webhook from a webhook-capable active courier while it holds shipments.
        'courier_webhook_silence' => [
            'hours' => 6,
        ],

        // SMS failures in the window: fires when failures >= min_failures AND rate > rate_percent.
        'sms_failures' => [
            'window_minutes' => 30,
            'min_failures' => 5,
            'rate_percent' => 20,
        ],

        'backup_stale' => [
            'hours' => 26,
        ],

        // docs/ops/backup-and-restore.md: a restore is rehearsed monthly.
        'restore_rehearsal_stale' => [
            'days' => 35,
        ],

        'queue_depth' => [
            'threshold' => 500,
        ],

        // Disk holding storage/ (logs, backup temp, uploads before off-host copy).
        'disk_space' => [
            'min_free_percent' => 10,
            'min_free_gb' => 2,
        ],
    ],

    // Sentry in the admin SPA (browser). Off unless SENTRY_ADMIN_DSN is set; the DSN is a
    // public ingest key (it ships to every admin browser), never the auth token. Release and
    // environment come from config/sentry.php (SENTRY_RELEASE = git SHA, SENTRY_ENVIRONMENT).
    'sentry_admin' => [
        'dsn' => env('SENTRY_ADMIN_DSN') ?: null, // empty means off
        'sample_rate' => env('SENTRY_ADMIN_SAMPLE_RATE', '1.0'),
        'traces_sample_rate' => env('SENTRY_ADMIN_TRACES_SAMPLE_RATE', '0'),
    ],

    // The synthetic add-to-cart check (§11: every 15 min). It needs a storefront token with
    // `storefront:read` (php artisan toymart:storefront-token); without one it is skipped.
    'synthetic' => [
        'api_url' => env('MONITORING_SYNTHETIC_API_URL', env('APP_URL')),
        'token' => env('MONITORING_SYNTHETIC_TOKEN'),
        // Optional: always use this product; otherwise the first in-stock product listed.
        'product_slug' => env('MONITORING_SYNTHETIC_PRODUCT_SLUG'),
        'timeout_seconds' => (int) env('MONITORING_SYNTHETIC_TIMEOUT_SECONDS', 10),
    ],

];
