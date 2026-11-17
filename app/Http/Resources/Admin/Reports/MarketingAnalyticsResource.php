<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Marketing ROI for a Dhaka-date range (MarketingAnalytics). Money is a decimal string;
 * `return_ratio` is delivered revenue ÷ spend as a 2-dp string ("4.60") or null without
 * spend; deltas are percent strings or null. Spend is prorated by day (`rules.spend`);
 * delivered revenue is recognised on delivery (rule 10). The headline counts campaigns with
 * spend only; `unpaid_*` is what tagged campaigns without spend brought.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('MarketingAnalytics')]
final class MarketingAnalyticsResource extends ReportResource
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
            /** @var array{spend: string, revenue: string, orders: int, delivered_orders: int, campaigns: int, unpaid_revenue: string, unpaid_orders: int, return_ratio: string|null, cost_per_order: string|null} */
            'summary' => self::plain($r['summary']),
            /** @var array{spend: string, revenue: string, orders: int, delivered_orders: int, campaigns: int, unpaid_revenue: string, unpaid_orders: int, return_ratio: string|null, cost_per_order: string|null} */
            'previous' => self::plain($r['previous']),
            /** @var array{spend: string|null, revenue: string|null, orders: string|null} */
            'deltas' => $r['deltas'],
            /** @var list<array{campaign: string, source: string|null, has_spend: bool, spend: string, orders: int, delivered_orders: int, delivered_revenue: string, return_ratio: string|null, cost_per_order: string|null}> */
            'campaigns' => self::plain($r['campaigns']),
            /** @var array{orders: int, delivered_orders: int, delivered_revenue: string, sources: list<string>} */
            'untagged' => self::plain($r['untagged']),
            /** @var list<array{kind: 'coupon'|'promotion', id: int, name: string, name_bn: string|null, type: string, orders: int, new_customers: int, cost: string, order_value: string, delivered_orders: int, delivered_value: string}> */
            'deals' => self::plain($r['deals']),
            /** @var array{coupon: array{cost: string, orders: int, new_customers: int}, promotion: array{cost: string, orders: int, new_customers: int}} */
            'deal_totals' => self::plain($r['deal_totals']),
            /** @var array{best_campaign: array{campaign: string, source: string|null, spend: string, delivered_revenue: string, return_ratio: string|null}|null, top_new_customer_coupon: array{name: string, new_customers: int}|null} */
            'highlights' => self::plain($r['highlights']),
            /** @var bool */
            'empty' => $r['empty'],
            /** @var array{spend: 'prorated_by_day', revenue: 'delivered_net_product_revenue'} */
            'rules' => $r['rules'],
        ];
    }
}
