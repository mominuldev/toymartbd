<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Product analytics for a Dhaka-date range (ProductAnalytics). Money is a decimal string;
 * percentages are 2-dp strings or null when there is nothing to divide by. Revenue, units
 * and returns are over orders delivered in the range; `units_paced` and `days_of_stock` use
 * units on orders placed in the range and not cancelled. Cost, profit, margin and stock
 * value are null unless `profit_visible`.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('ProductAnalytics')]
final class ProductAnalyticsResource extends ReportResource
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
            /** @var bool */
            'profit_visible' => $r['profit_visible'],
            /** @var 'revenue'|'units'|'margin'|'returns' */
            'sort' => $r['sort'],
            /** @var array{running_out_days: int, urgent_days: int, min_units_for_returns: int} */
            'thresholds' => $r['thresholds'],
            /** @var array{products_sold: int, products_on_sale: int, units_delivered: int, units_delivered_previous: int, units_delivered_change: int, units_delta: string|null, net_revenue: string, gross_profit: string|null, margin_percent: string|null, units_returned: int, return_rate: string|null, running_out_count: int, running_out_urgent_count: int, not_selling_count: int} */
            'summary' => self::plain($r['summary']),
            /** @var array{best_seller: array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, units_delivered: int, units_returned: int, units_kept: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null, units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}|null, most_returned: array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, units_delivered: int, units_returned: int, units_kept: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null, units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}|null} */
            'highlights' => self::plain($r['highlights']),
            /** @var list<array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, units_delivered: int, units_returned: int, units_kept: int, net_revenue: string, cogs: string|null, gross_profit: string|null, margin_percent: string|null, return_rate: string|null, units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}> */
            'products' => self::plain($r['products']),
            /** @var list<array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}> */
            'running_out' => self::plain($r['running_out']),
            /** @var list<array{product_id: int|null, name: string, name_bn: string|null, category_name: string|null, category_name_bn: string|null, units_paced: int, stock_left: int, days_of_stock: int|null, stock_value: string|null}> */
            'not_selling' => self::plain($r['not_selling']),
        ];
    }
}
