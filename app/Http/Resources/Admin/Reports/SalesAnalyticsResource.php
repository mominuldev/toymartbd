<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Sales analytics for a Dhaka-date range (SalesAnalytics). Money is a decimal string;
 * percentages are 2-dp strings or null when there is nothing to divide by. `placed` is
 * demand at checkout value — not revenue; `recognised` is the ledger's revenue on delivery
 * (rule 10). Cost and profit fields are null unless `profit_visible`.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('SalesAnalytics')]
final class SalesAnalyticsResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var array{preset: string, from: string, to: string, days: int} */
            'range' => $r['range'],
            /** @var array{preset: string, from: string, to: string, days: int} */
            'comparison' => $r['comparison'],
            /** @var 'day'|'week'|'month' */
            'granularity' => $r['granularity'],
            /** @var bool */
            'profit_visible' => $r['profit_visible'],
            /** @var 'ledger'|'summaries'|'mixed' */
            'source' => $r['source'],
            /** @var array{placed: array{orders: int, value: string, cancelled_orders: int, cancelled_value: string, net_orders: int, net_value: string, units: int, aov: string|null, cancel_rate: string|null}, delivered: array{orders: int, value: string, aov: string|null}, recognised: array{product_revenue: string, delivery_revenue: string, gross_revenue: string, discounts: string, net_revenue: string, cogs: string|null, gross_profit: string|null, gross_margin_percent: string|null}, customers: array{customers: int, returning: int, new: int, repeat_rate: string|null}} */
            'summary' => self::plain($r['summary']),
            /** @var array{placed: array{orders: int, value: string, cancelled_orders: int, cancelled_value: string, net_orders: int, net_value: string, units: int, aov: string|null, cancel_rate: string|null}, delivered: array{orders: int, value: string, aov: string|null}, recognised: array{product_revenue: string, delivery_revenue: string, gross_revenue: string, discounts: string, net_revenue: string, cogs: string|null, gross_profit: string|null, gross_margin_percent: string|null}, customers: array{customers: int, returning: int, new: int, repeat_rate: string|null}} */
            'previous' => self::plain($r['previous']),
            /** @var array{placed_value: string|null, placed_orders: string|null, aov: string|null, recognised_revenue: string|null, repeat_rate_points: string|null} */
            'deltas' => $r['deltas'],
            /**
             * One row per bucket (Saturday-start weeks), Dhaka dates. `previous_*` is the
             * comparison range's bucket at the same position, or null.
             *
             * @var list<array{key: string, from: string, to: string, days: int, placed_orders: int, placed_value: string, units: int, cancelled_orders: int, outcomes: array{delivered: int, shipped: int, open: int, cancelled: int, returned: int}, delivered_orders: int, recognised_revenue: string, gross_profit: string|null, previous_placed_orders: int|null, previous_placed_value: string|null, previous_units: int|null}>
             */
            'trend' => self::plain($r['trend']),
            /** @var list<array{category_id: int|null, name: string, name_bn: string|null, orders: int, units_sold: int, units_returned: int, units: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null}> */
            'categories' => self::plain($r['categories']),
            /** @var list<array{brand_id: int|null, name: string|null, name_bn: string|null, orders: int, units_sold: int, units_returned: int, units: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null}> */
            'brands' => self::plain($r['brands']),
            /** @var array{orders: int, units_sold: int, units_returned: int, units: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null} */
            'lines_total' => self::plain($r['lines_total']),
            /** @var array{ledger_product_revenue: string, lines_net_revenue: string, difference: string} */
            'reconciliation' => self::plain($r['reconciliation']),
            /** @var list<array{method: string, orders: int, value: string, orders_percent: string|null, value_percent: string|null, aov: string|null}> */
            'payment_methods' => self::plain($r['payment_methods']),
            /** @var list<array{source: string, orders: int, value: string, orders_percent: string|null, value_percent: string|null, aov: string|null}> */
            'channels' => self::plain($r['channels']),
            /** @var array{orders_with_discount: int, orders_with_discount_percent: string|null, discount_total: string, coupon_discount: string, promotion_discount: string, shipping_discount: string, aov_with_discount: string|null, aov_without_discount: string|null, recognised_discounts: string, coupons: list<array{code: string, orders: int, discount: string, value: string}>, promotions: list<array{promotion_id: int, name: string, name_bn: string|null, orders: int, discount: string}>} */
            'discounts' => self::plain($r['discounts']),
            /** @var list<array{source: string, placed_lines: int, placed_units: int, placed_orders: int, placed_value: string, placed_value_percent: string|null, delivered_units: int, net_revenue: string, gross_profit: string|null, return_rate: string|null}> */
            'recommendations' => self::plain($r['recommendations']),
            /**
             * Conversion is not reported: no server-side page views and consent-gated browser
             * events leave no defensible session denominator.
             *
             * @var array{available: false, reason: 'no_session_denominator'}
             */
            'conversion' => $r['conversion'],
        ];
    }
}
