<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Customer analytics for a Dhaka-date range (Reporting\Queries\Customers\CustomerAnalytics).
 * Every figure is the server's: counts are integers, money and rates decimal strings, a rate
 * null when there is nothing to divide by. Lifetime value = DELIVERED revenue from the ledger
 * (order_financials: line totals after discounts, net of refunds, excluding delivery).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('CustomerAnalytics')]
final class CustomerAnalyticsResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var array{preset: string, from: string, to: string, days: int} */
            'range' => $r['range'],
            /**
             * Segment thresholds in force — PLACEHOLDERS until the owner sets them (config/customers.php).
             *
             * @var array{vip_min_lifetime_value: string, at_risk_after_days: int, lapsed_after_days: int}
             */
            'thresholds' => $r['thresholds'],
            /**
             * One row per segment in precedence order, every segment present.
             *
             * @var list<array{segment: 'no_orders'|'lapsed'|'at_risk'|'vip'|'new'|'active', customers: int, lifetime_value: string}>
             */
            'segments' => $r['segments'],
            /**
             * Over customers with a delivered lifetime value above zero. `to` null = open-ended bucket.
             *
             * @var array{customers_with_value: int, total: string, average: string|null, median: string|null, buckets: list<array{from: string, to: string|null, customers: int, lifetime_value: string}>}
             */
            'lifetime_value' => $r['lifetime_value'],
            /**
             * All time: customers with 2+ delivered orders ÷ customers with 1+.
             *
             * @var array{customers_with_delivered: int, repeat_customers: int, rate: string|null}
             */
            'repeat_purchase' => $r['repeat_purchase'],
            /**
             * Customers who placed a non-cancelled order in the range: first-time vs returning.
             *
             * @var array{customers: int, new_customers: int, returning_customers: int, returning_share: string|null}
             */
            'period' => $r['period'],
            /**
             * Acquisition cohorts by the Dhaka month of the first non-cancelled order, for every
             * month the range touches. `retention[k]` = share of the cohort that ordered in month k.
             *
             * @var list<array{month: string, customers: int, lifetime_value: string, average_lifetime_value: string|null, retention: list<array{offset: int, month: string, customers: int, rate: string|null}>}>
             */
            'cohorts' => $r['cohorts'],
            /**
             * Highest lifetime values; phone always masked.
             *
             * @var list<array{id: int, name: string, phone_masked: string, orders_count: int, delivered_orders_count: int, lifetime_value: string, last_order_at: string|null}>
             */
            'top_customers' => $r['top_customers'],
        ];
    }
}
