<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The P&L by day, week (Saturday–Friday), month or year (ProfitAndLoss::series). Buckets
 * are Dhaka dates, labelled by their first day and clipped to the requested range.
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('PnlSeries')]
final class PnlSeriesResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** @var 'day'|'week'|'month'|'year' */
            'granularity' => $s['granularity'],
            /** @var string */
            'from' => $s['from'],
            /** @var string */
            'to' => $s['to'],
            /** @var 'ledger'|'summaries'|'mixed' */
            'source' => $s['source'],
            /** @var bool False when the reader lacks catalog.view_cost. */
            'item_costs_visible' => $s['item_costs_visible'] ?? true,
            /**
             * One bucket per period, oldest first, including empty ones. Without catalog.view_cost
             * the `cogs` line and the gross profit / profit after delivery / profit after losses
             * subtotals are null (cost of goods could be backed out of them).
             *
             * @var list<array{key: string, from: string, to: string, days: int, totals: array{gross_revenue: string, net_revenue: string, gross_profit: string|null, profit_after_delivery: string|null, profit_after_losses: string|null, net_profit: string}, lines: array<string, string|null>, net_margin_percent: string|null}>
             */
            'buckets' => self::plain($s['buckets']),
            /** @var array{gross_revenue: string, net_revenue: string, gross_profit: string|null, profit_after_delivery: string|null, profit_after_losses: string|null, net_profit: string} */
            'totals' => self::plain($s['totals']),
        ];
    }
}
