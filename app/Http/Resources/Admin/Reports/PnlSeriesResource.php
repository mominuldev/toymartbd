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
            /**
             * One bucket per period, oldest first, including empty ones.
             *
             * @var list<array{key: string, from: string, to: string, days: int, totals: array{gross_revenue: string, net_revenue: string, gross_profit: string, profit_after_delivery: string, profit_after_losses: string, net_profit: string}, lines: array<string, string>, net_margin_percent: string|null}>
             */
            'buckets' => self::plain($s['buckets']),
            /** @var array{gross_revenue: string, net_revenue: string, gross_profit: string, profit_after_delivery: string, profit_after_losses: string, net_profit: string} */
            'totals' => self::plain($s['totals']),
        ];
    }
}
