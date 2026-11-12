<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Profitability of the lines of orders delivered in the period, grouped by product,
 * primary category or recommendation source (Profitability). COGS is the frozen
 * `cost_price_snapshot` (rule 7); returned units and completed refunds are netted.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('ProfitabilityReport')]
final class ProfitabilityReportResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var 'product'|'category'|'source' */
            'by' => $r['by'],
            /** @var string */
            'from' => $r['from'],
            /** @var string */
            'to' => $r['to'],
            /** @var string */
            'sort' => $r['sort'],
            /**
             * Sorted rows, at most 1,000 (`truncated` says when there were more). Product rows
             * carry product_id/sku/category; category rows category_id/parent_id/products;
             * source rows `source` (`none` = not from a recommendation) and products.
             *
             * @var list<array{name: string, name_bn?: string|null, product_id?: int|null, sku?: string|null, category_id?: int|null, category_name?: string|null, category_name_bn?: string|null, parent_id?: int|null, source?: string, products?: int, orders: int, units: int, units_sold: int, units_returned: int, gross_revenue: string, reversals: string, net_revenue: string, cogs: string, gross_profit: string, margin_percent: string|null}>
             */
            'data' => self::plain($r['rows']),
            /**
             * Over every row, not only the returned ones.
             *
             * @var array{orders: int, rows: int, units: int, units_sold: int, units_returned: int, gross_revenue: string, reversals: string, net_revenue: string, cogs: string, gross_profit: string, margin_percent: string|null}
             */
            'totals' => self::plain($r['totals']),
            /** @var bool */
            'truncated' => $r['truncated'],
        ];
    }
}
