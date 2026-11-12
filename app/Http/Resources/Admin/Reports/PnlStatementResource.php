<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The profit & loss statement for a Dhaka-date period (ProfitAndLoss::statement): five
 * blocks of lines with running subtotals, the cash view kept apart, the reconciliation
 * split and the "not in this statement" figures. Every amount is the server's, as a
 * decimal string signed by its effect on profit (costs negative).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('PnlStatement')]
final class PnlStatementResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** @var string First Dhaka day, inclusive. */
            'from' => $s['from'],
            /** @var string Last Dhaka day, inclusive. */
            'to' => $s['to'],
            /** @var int */
            'days' => $s['days'],
            /**
             * The five blocks in order. A `line` carries the ledger types it sums; a
             * `subtotal` is the running total after the lines above it.
             *
             * @var list<array{key: 'revenue'|'goods'|'delivery'|'losses'|'running', number: int, rows: list<array{kind: 'line'|'subtotal', key: string, types?: list<string>, amount: string}>}>
             */
            'blocks' => self::plain($s['blocks']),
            /** @var array{gross_revenue: string, net_revenue: string, gross_profit: string, profit_after_delivery: string, profit_after_losses: string, net_profit: string} */
            'totals' => self::plain($s['totals']),
            /** @var string|null Gross profit as % of net revenue, 2 dp; null without revenue. */
            'gross_margin_percent' => $s['gross_margin_percent'],
            /** @var string|null Net profit as % of net revenue, 2 dp; null without revenue. */
            'net_margin_percent' => $s['net_margin_percent'],
            /**
             * Recognised entries of a type that does not belong in a P&L (e.g. a cash-only
             * type flagged as revenue). Not counted above; should always be empty.
             *
             * @var list<array{type: string, amount: string}>
             */
            'unmapped' => self::plain($s['unmapped']),
            /**
             * Recognised entries with an expense category, per category, largest cost first.
             *
             * @var list<array{category_id: int, name: string, name_bn: string|null, type: string, parent_id: int|null, amount: string}>
             */
            'expenses_by_category' => self::plain($s['expenses_by_category']),
            /**
             * Cash movements in the period (`is_cash_movement`), kept apart from the P&L.
             *
             * @var array{in: string, out: string, net: string, by_type: list<array{type: string, in: string, out: string}>}
             */
            'cash' => self::plain($s['cash']),
            /**
             * Recognised net split by whether the entry belongs to an order. `reconciled` is
             * true when the two parts add up to the statement's net profit.
             *
             * @var array{orders: string, order_count: int, other: string, total: string, reconciled: bool}
             */
            'reconciliation' => self::plain($s['reconciliation']),
            /**
             * Orders placed and not yet delivered, cancelled or returned — current, not per
             * period. Not in the statement.
             *
             * @var array{count: int, value: string}
             */
            'open_orders' => self::plain($s['open_orders']),
            /**
             * Inventory on hand at cost, now; only with catalog.view_cost. Not in the statement.
             *
             * @var string|null
             */
            'inventory_at_cost' => $s['inventory_at_cost'] ?? null,
            /**
             * The comparison period's subtotals and the % change of each; null when `compare=none`.
             *
             * @var array{kind: 'previous_period'|'previous_year', from: string, to: string, totals: array{gross_revenue: string, net_revenue: string, gross_profit: string, profit_after_delivery: string, profit_after_losses: string, net_profit: string}, deltas: array{gross_revenue: string|null, net_revenue: string|null, gross_profit: string|null, profit_after_delivery: string|null, profit_after_losses: string|null, net_profit: string|null}}|null
             */
            'comparison' => self::plain($s['comparison']),
            /** @var 'ledger'|'summaries'|'mixed' Where the figures were read from. */
            'source' => $s['source'],
        ];
    }
}
