<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer segments and analytics (PRD U-01, U-02, U-05; roadmap Phase 8)
|--------------------------------------------------------------------------
|
| Segment thresholds confirmed by the owner on 2026-11-13. They drive the RFM-style
| segments on the admin Customers list and the Customer analytics page. Change them here
| (or by env) without touching code; every figure is recomputed on read.
|
| Lifetime value (LTV) everywhere is DELIVERED revenue from the ledger projection
| (`order_financials.revenue − order_financials.refund_amount`: line totals after
| discounts, net of refunds, excluding delivery charges). An order that was placed but
| never delivered contributes nothing (rule 10). Owner decision 2026-11-13: LTV excludes
| delivery charges.
|
| Segments are mutually exclusive and assigned in this order:
|   no_orders  never placed a (non-cancelled) order
|   lapsed     last order ≥ lapsed_after_days ago
|   at_risk    last order ≥ at_risk_after_days ago
|   vip        LTV ≥ vip_min_lifetime_value
|   new        exactly one (non-cancelled) order
|   active     two or more orders, ordered recently
|
*/

return [
    'segments' => [
        // Owner-confirmed 2026-11-13: VIP from ৳15,000 LTV. Decimal string, BDT.
        'vip_min_lifetime_value' => env('CUSTOMERS_VIP_MIN_LTV', '15000.00'),
        // Owner-confirmed 2026-11-13: at risk after 90 days without an order.
        'at_risk_after_days' => (int) env('CUSTOMERS_AT_RISK_AFTER_DAYS', 90),
        // Owner-confirmed 2026-11-13: lapsed after 180 days without an order.
        'lapsed_after_days' => (int) env('CUSTOMERS_LAPSED_AFTER_DAYS', 180),
    ],

    'analytics' => [
        // PLACEHOLDER (owner): upper bounds (BDT, exclusive) of the LTV distribution buckets;
        // a final open-ended bucket covers everything above the last bound.
        'ltv_buckets' => ['1000.00', '2500.00', '5000.00', '10000.00', '15000.00'],
        // How many customers the "Top customers" table lists.
        'top_customers' => 10,
        // The most monthly cohorts one request may return.
        'max_cohort_months' => 24,
    ],
];
