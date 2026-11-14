<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Unified inbox (Figma B · 22) — docs/api/inbox.md
|--------------------------------------------------------------------------
|
| NO WhatsApp Business account, Meta app or Facebook Page connection exists yet
| (2026-11-15). Every channel therefore defaults to the `log` driver, which sends
| nothing and records a masked line, and every channel reports `verified = false`.
| The Meta drivers (`cloud`, `messenger`) are written against Meta's published docs
| and hand-authored fixtures only; never claim they work live until an owner has
| connected a real account and someone has watched a real message arrive.
|
| Credentials are read from `settings` first (keys `inbox.*`, group notification,
| secrets with `is_encrypted`, rule 20) and fall back to these env values —
| App\Domain\Inbox\Services\InboxChannelSettings.
|
*/

return [
    // Outbound sends and webhook processing: queued, after commit (rule 4).
    'queue' => env('INBOX_QUEUE', 'notify'),
    'tries' => 3,
    'backoff' => [30, 120],

    // Meta's customer-service window: free-form replies only within 24 h of the
    // customer's last message (WhatsApp send-messages guide; Messenger send-messages guide).
    'service_window_hours' => 24,

    // Graph API version used in Meta's current examples (v27.0, read 2026-11-15).
    'graph_version' => env('META_GRAPH_VERSION', 'v27.0'),
    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'timeout' => 10,

    'whatsapp' => [
        // log | cloud
        'driver' => env('INBOX_WHATSAPP_DRIVER', 'log'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
    ],

    'messenger' => [
        // log | messenger
        'driver' => env('INBOX_MESSENGER_DRIVER', 'log'),
        'page_id' => env('MESSENGER_PAGE_ID'),
        'page_access_token' => env('MESSENGER_PAGE_ACCESS_TOKEN'),
        'app_secret' => env('MESSENGER_APP_SECRET'),
        'verify_token' => env('MESSENGER_VERIFY_TOKEN'),
    ],

    // No storefront chat widget exists; live chat is a channel with no inbound path yet.
    'live_chat' => [
        'driver' => 'log',
    ],

    // Quick actions compose links from these. The tracking link follows the order
    // notifications' short-link convention (config notifications.short_link_base).
    'storefront_url' => env('STOREFRONT_URL', 'https://toymartbd.com'),

    // Raw verified webhook payloads are kept this long for disputes, then pruned.
    'receipt_retention_days' => 90,
];
