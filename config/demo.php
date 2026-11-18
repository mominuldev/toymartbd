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

    /*
     * Demo branches (DemoInventorySeeder): Chattogram and Sylhet beside the main shop, with
     * multiple branches switched on. Off by default — a seeded store is a single shop with
     * multi-branch off (owner request 2026-11-18). The admin E2E and a11y runs
     * (e2e/demo-seed.env.sh, sourced by both serve.sh) turn it on, because their POS, transfer
     * and branch-setup specs need the branches.
     */
    'branches' => (bool) env('DEMO_SEED_BRANCHES', false),

    /* How many days back the demo order history starts, ending today (Dhaka time). */
    'history_days' => (int) env('DEMO_SEED_HISTORY_DAYS', 60),
];
