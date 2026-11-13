<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Notifications (P4-NOT-01..03, P4-I18N-03)
|--------------------------------------------------------------------------
|
| Every cost and every business value below is a PLACEHOLDER until the owner
| signs a provider contract (CLAUDE.md "Known gaps"; UI-UX-DESIGN-SPEC §10).
| The research (docs/_research/agent4-bd-market-and-payments.md §4) found local
| masked SMS at roughly ৳0.48–0.64 a segment and WhatsApp utility at ≈৳0.45, but
| no merchant account exists, so nothing here is a contracted rate.
|
| Channel policy (rules 32b, 39): order updates go over WhatsApp utility first,
| SMS only as the fallback; OTP goes over SMS, never WhatsApp.
|
*/

return [

    // Queue for every send (SYSTEM-ARCHITECTURE §6 queue table): 3 tries, then dead-letter.
    'queue' => env('NOTIFICATIONS_QUEUE', 'notify'),
    'tries' => 3,
    'backoff' => [30, 120],

    'sms' => [
        // log | null | reve (owner's provider, 2026-11-13) | sms_net_bd (UNVERIFIED skeleton)
        'driver' => env('SMS_DRIVER', 'log'),
        'log_channel' => env('SMS_LOG_CHANNEL'),
        // PLACEHOLDER: sms.bd Basic-slab masking rate observed 2026-11-07, not a contract.
        // Applied by every driver except `null`, so cost accounting is visible in dev too.
        'cost_per_segment' => env('SMS_COST_PER_SEGMENT', '0.64'),
        'drivers' => [
            // REVE SMS reseller endpoint (App\Domain\Notification\Drivers\ReveSmsGateway).
            // Credentials only ever come from env; never commit them.
            'reve' => [
                'base_url' => env('SMS_REVE_BASE_URL', 'https://smpp.ajuratech.com:7790'),
                'api_key' => env('SMS_REVE_API_KEY'),
                'secret_key' => env('SMS_REVE_SECRET_KEY'),
                'sender_id' => env('SMS_REVE_SENDER_ID'),
                'timeout' => (int) env('SMS_REVE_TIMEOUT', 10),
            ],
            'sms_net_bd' => [
                'base_url' => env('SMS_NET_BD_BASE_URL', 'https://api.sms.net.bd'),
                'api_key' => env('SMS_NET_BD_API_KEY'),
                'sender_id' => env('SMS_NET_BD_SENDER_ID'),
                'timeout' => 10,
                'verified' => false,
            ],
        ],
    ],

    'whatsapp' => [
        // log | null. No WhatsApp Business (BSP or Cloud API) account exists yet.
        'driver' => env('WHATSAPP_DRIVER', 'log'),
        // PLACEHOLDER: ≈৳0.45 utility rate (PRD §6a). Meta's pricing moves; confirm before launch.
        'cost_per_message' => env('WHATSAPP_COST_PER_MESSAGE', '0.45'),
        'enabled' => (bool) env('WHATSAPP_ENABLED', true),
    ],

    'email' => [
        'enabled' => (bool) env('NOTIFICATION_EMAIL_ENABLED', true),
        'cost_per_message' => '0.00',
    ],

    // PLACEHOLDER business values rendered into templates (UI-UX-DESIGN-SPEC §10).
    'hotline' => env('STORE_HOTLINE', '09600123456'),
    // `tmbd.co` must actually be registered (05-content-and-assets-spec open item 7).
    'short_link_base' => env('NOTIFICATION_SHORT_LINK_BASE', 'tmbd.co'),
    'store_url' => env('STORE_PUBLIC_DOMAIN', 'toymartbd.com'),
];
