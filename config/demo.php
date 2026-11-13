<?php

declare(strict_types=1);

/*
 * Local demo data. DatabaseSeeder only ever runs the demo seeders in `local` or `testing`
 * (a seeded login anywhere else is a back door); these flags choose how much of it.
 */
return [
    /*
     * Transactional demo data (DemoDataSeeder): staff, customers, ~60 days of orders through
     * their whole lifecycle, payments, shipments, returns, courier settlement, purchasing,
     * expenses, marketing, reviews, content and SEO. On by default in `local` only — the
     * Pest suite and the admin E2E run (e2e/admin/serve.sh) count orders exactly and keep it off.
     */
    'transactions' => (bool) env('DEMO_SEED_TRANSACTIONS', env('APP_ENV') === 'local'),

    /* How many days back the demo order history starts, ending today (Dhaka time). */
    'history_days' => (int) env('DEMO_SEED_HISTORY_DAYS', 60),
];
