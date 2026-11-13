<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Online payments (P4-PAY-02/03/04)
|--------------------------------------------------------------------------
|
| COD needs no configuration (rule 33). An online gateway is offered only when its
| `payment_gateways` row is active, it has credentials, and — in production — the row
| is `verified` (rule 33a spirit). No merchant account exists yet (Gate 1 decision 5):
| everything below is sandbox-shaped and every business value is a PLACEHOLDER.
|
| Credentials never appear in logs, exceptions or stored payloads (rule 20).
|
*/

return [
    'online' => [
        // PLACEHOLDER: how long an unpaid online order keeps its stock reservation, counted
        // from the latest payment attempt. The sweeper then expires it and releases stock.
        'expiry_minutes' => (int) env('PAYMENT_ONLINE_EXPIRY_MINUTES', 30),
        // PLACEHOLDER: payment attempts (sessions) one order may open.
        'max_attempts' => (int) env('PAYMENT_ONLINE_MAX_ATTEMPTS', 5),
    ],

    'sslcommerz' => [
        'store_id' => env('SSLCOMMERZ_STORE_ID'),
        'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
        'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
        // Published v4 hosts (docs/_research/agent4-bd-market-and-payments.md §1.2).
        'hosts' => [
            'sandbox' => 'https://sandbox.sslcommerz.com',
            'live' => 'https://securepay.sslcommerz.com',
        ],
        'timeout_seconds' => (int) env('SSLCOMMERZ_TIMEOUT', 15),
        // Where the customer's browser returns: the storefront's route handler, which
        // forwards the signed POST to the API. Defaults to STOREFRONT_URL.
        'return_base_url' => env('SSLCOMMERZ_RETURN_URL', env('STOREFRONT_URL')),
        // PLACEHOLDER: SSLCommerz requires cus_email; checkout's email is optional.
        'fallback_email' => env('SSLCOMMERZ_FALLBACK_EMAIL', 'orders@toymartbd.com'),
        // Optional IPN source-IP allowlist (comma separated). Empty = not enforced; the
        // signature + validation re-query are the controls either way. Published IPs:
        // sandbox 103.26.139.87; live 103.26.139.81, 103.132.153.81.
        'ipn_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('SSLCOMMERZ_IPN_ALLOWED_IPS', ''))))),
    ],
];
