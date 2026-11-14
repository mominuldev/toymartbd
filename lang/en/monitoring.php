<?php

declare(strict_types=1);

/*
| Monitoring — business alerts to the owner channel (P10, docs/ops/observability.md).
| Parameters are counts, durations, provider codes and queue names only — never PII (rule 20).
*/
return [

    'names' => [
        'no_orders' => 'No orders',
        'courier_booking_failures' => 'Courier booking failures',
        'failed_jobs' => 'Failed background jobs',
        'courier_poll_silence' => 'Courier status sync silent',
        'courier_webhook_silence' => 'Courier webhooks silent',
        'sms_failures' => 'SMS failures',
        'backup_stale' => 'Backup overdue',
        'restore_rehearsal_stale' => 'Restore rehearsal overdue',
        'horizon_down' => 'Queue workers down',
        'queue_depth' => 'Queue backlog',
        'disk_space' => 'Low disk space',
    ],

    'severity' => [
        'warning' => 'Warning',
        'critical' => 'Critical',
    ],

    'summary' => [
        'ok' => 'OK.',
        'not_applicable' => 'Not applicable: :reason.',
        'no_orders' => 'No orders placed in the last :hours business hours (since :since Dhaka time). Check that checkout works.',
        'courier_booking_failures' => ':failures of :attempts courier bookings failed in the last :minutes minutes (:rate%).',
        'failed_jobs' => ':count background jobs failed in the last :minutes minutes (threshold :threshold).',
        'courier_poll_silence' => 'No successful answer from :providers for over :minutes minutes while it holds shipments.',
        'courier_webhook_silence' => 'No webhook from :providers for over :hours hours while it holds shipments.',
        'sms_failures' => ':failures of :total SMS sends failed in the last :minutes minutes (:rate%). Check the SMS balance and provider.',
        'backup_stale' => 'The last successful backup is :age hours old (limit :hours hours).',
        'backup_never' => 'No successful backup has been recorded (limit :hours hours).',
        'restore_rehearsal_stale' => 'The last passed restore rehearsal is :age days old (limit :days days).',
        'restore_rehearsal_never' => 'No passed restore rehearsal has been recorded (limit :days days).',
        'horizon_down' => 'Horizon is not running: no queued work (order messages, courier bookings, SMS) is being processed.',
        'queue_depth' => 'Queue backlog above :threshold: :queues.',
        'queue_unreachable' => 'The queue backend cannot be reached.',
        'disk_space' => 'Only :free_gb GB (:percent%) free on the application disk.',
    ],

    'mail' => [
        'test_tag' => 'TEST',
        'subject_firing' => ':severity alert: :name',
        'subject_reminder' => 'Still firing (:severity): :name',
        'subject_resolved' => 'Resolved: :name',
        'intro_firing' => 'A business alert started firing.',
        'intro_reminder' => 'This alert is still firing.',
        'intro_resolved' => 'This alert has resolved.',
        'test_note' => 'This is a TEST notice sent by `php artisan toymart:alerts:test`. Nothing is wrong.',
        'checked_at' => 'Checked at (UTC)',
        'runbook' => 'What to do: docs/ops/observability.md, "Business alerts".',
        'footer' => 'Toy Mart BD monitoring. Change recipients with MONITORING_ALERT_EMAILS / MONITORING_ALERT_PHONES.',
    ],

    // Latin, not Bangla: an SMS in Bangla costs 2–3× more per segment (rule 39).
    'sms' => ':tagToy Mart BD :kind :name: :summary',

];
