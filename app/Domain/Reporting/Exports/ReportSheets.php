<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Support\Money;

/**
 * Turns report arrays (SalesAnalytics, ProfitAndLoss statement and series, Profitability,
 * InventoryValuationReport, CourierPerformanceReport) into export sections. Headers are stable machine keys like the other report CSVs; labels
 * and sheet titles are in the requested locale. Numerals are Western in both (rule 36).
 * A value the viewer may not see (null under a hidden-profit report) is an empty cell.
 */
final class ReportSheets
{
    /**
     * @param  array<string, mixed>  $report  SalesAnalytics::report()
     * @return list<Sheet>
     */
    public static function sales(array $report, string $locale): array
    {
        $t = static fn (string $key): string => (string) __('analytics.'.$key, [], $locale);
        $s = $report['summary'];
        $metric = static fn (string $key, mixed $value): array => [$key, $t('metric.'.$key), self::cell($value)];

        $summary = [
            ['range', $t('metric.range'), $report['range']['from'].' – '.$report['range']['to']],
            $metric('placed_orders', $s['placed']['orders']),
            $metric('placed_value', $s['placed']['value']),
            $metric('cancelled_orders', $s['placed']['cancelled_orders']),
            $metric('cancelled_value', $s['placed']['cancelled_value']),
            $metric('cancel_rate', $s['placed']['cancel_rate']),
            $metric('net_orders', $s['placed']['net_orders']),
            $metric('net_value', $s['placed']['net_value']),
            $metric('units', $s['placed']['units']),
            $metric('aov', $s['placed']['aov']),
            ['delivered_orders', $t('metric.delivered_orders'), self::cell($s['delivered']['orders'])],
            ['delivered_value', $t('metric.delivered_value'), self::cell($s['delivered']['value'])],
            ['delivered_aov', $t('metric.delivered_aov'), self::cell($s['delivered']['aov'])],
            $metric('product_revenue', $s['recognised']['product_revenue']),
            $metric('delivery_revenue', $s['recognised']['delivery_revenue']),
            $metric('gross_revenue', $s['recognised']['gross_revenue']),
            $metric('discounts', $s['recognised']['discounts']),
            $metric('net_revenue', $s['recognised']['net_revenue']),
        ];
        if ($report['profit_visible']) {
            $summary[] = $metric('cogs', $s['recognised']['cogs']);
            $summary[] = $metric('gross_profit', $s['recognised']['gross_profit']);
            $summary[] = $metric('gross_margin_percent', $s['recognised']['gross_margin_percent']);
        }
        array_push(
            $summary,
            $metric('customers', $s['customers']['customers']),
            $metric('returning', $s['customers']['returning']),
            $metric('new', $s['customers']['new']),
            $metric('repeat_rate', $s['customers']['repeat_rate']),
            ['conversion', $t('metric.conversion'), $t('metric.conversion_unavailable')],
        );

        $profit = $report['profit_visible'];
        $trendHeader = ['from', 'to', 'days', 'placed_orders', 'placed_value', 'units', 'cancelled_orders', 'delivered_orders', 'recognised_revenue', ...($profit ? ['gross_profit'] : []), 'previous_placed_value'];
        $performanceHeader = ['id', 'name', 'name_bn', 'orders', 'units_sold', 'units_returned', 'return_rate', 'net_revenue', ...($profit ? ['cogs', 'gross_profit', 'margin_percent'] : [])];
        $performance = static fn (array $rows, string $idKey, string $fallback): array => array_map(
            static fn (array $row): array => self::pick([
                ...$row,
                'id' => $row[$idKey],
                'name' => ($row['name'] ?? '') === '' ? $fallback : $row['name'],
            ], $performanceHeader),
            $rows,
        );
        $mixHeader = ['orders', 'orders_percent', 'value', 'value_percent', 'aov'];

        $discounts = $report['discounts'];
        $recommendationHeader = ['source', 'placed_lines', 'placed_units', 'placed_orders', 'placed_value', 'placed_value_percent', 'delivered_units', 'net_revenue', ...($profit ? ['gross_profit'] : []), 'return_rate'];

        return [
            new Sheet($t('sheet.summary'), ['key', 'metric', 'value'], $summary),
            new Sheet($t('sheet.trend'), $trendHeader, array_map(static fn (array $row): array => self::pick($row, $trendHeader), $report['trend'])),
            new Sheet($t('sheet.categories'), $performanceHeader, $performance($report['categories'], 'category_id', $t('no_category'))),
            new Sheet($t('sheet.brands'), $performanceHeader, $performance($report['brands'], 'brand_id', $t('no_brand'))),
            new Sheet($t('sheet.payment_methods'), ['method', ...$mixHeader], array_map(static fn (array $row): array => self::pick($row, ['method', ...$mixHeader]), $report['payment_methods'])),
            new Sheet($t('sheet.channels'), ['source', ...$mixHeader], array_map(static fn (array $row): array => self::pick($row, ['source', ...$mixHeader]), $report['channels'])),
            new Sheet($t('sheet.discounts'), ['key', 'value'], array_map(
                static fn (string $key): array => [$key, self::cell($discounts[$key])],
                ['orders_with_discount', 'orders_with_discount_percent', 'discount_total', 'coupon_discount', 'promotion_discount', 'shipping_discount', 'aov_with_discount', 'aov_without_discount', 'recognised_discounts'],
            )),
            new Sheet($t('sheet.coupons'), ['code', 'orders', 'discount', 'value'], array_map(static fn (array $row): array => self::pick($row, ['code', 'orders', 'discount', 'value']), $discounts['coupons'])),
            new Sheet($t('sheet.promotions'), ['promotion_id', 'name', 'name_bn', 'orders', 'discount'], array_map(static fn (array $row): array => self::pick($row, ['promotion_id', 'name', 'name_bn', 'orders', 'discount']), $discounts['promotions'])),
            new Sheet($t('sheet.recommendations'), $recommendationHeader, array_map(
                static fn (array $row): array => self::pick($row, $recommendationHeader),
                $report['recommendations'],
            )),
        ];
    }

    /**
     * The P&L statement as one section, one row per line — exactly the rows of the P&L CSV
     * export (`/finance/reports/pnl/export`), so the CSV, the XLSX and the scheduled email agree.
     *
     * @param  array<string, mixed>  $statement  ProfitAndLoss::statement()
     * @return list<Sheet>
     */
    public static function pnl(array $statement, string $locale): array
    {
        $t = static fn (string $key): string => (string) __('reports.pnl.'.$key, [], $locale);
        $rows = [];
        foreach ($statement['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                $rows[] = [(string) $block['number'], $t('block.'.$block['key']), $row['kind'], $row['key'], $t('line.'.$row['key']), implode(' ', $row['types'] ?? []), self::cell($row['amount'])];
            }
        }
        foreach ($statement['expenses_by_category'] as $category) {
            $rows[] = ['', $t('expenses_by_category'), 'category', (string) $category['category_id'], (string) $category['name'], self::cell($category['type']), self::cell($category['amount'])];
        }
        $rows[] = ['', $t('cash'), 'cash', 'cash_in', $t('cash_in'), '', self::cell($statement['cash']['in'])];
        $rows[] = ['', $t('cash'), 'cash', 'cash_out', $t('cash_out'), '', self::cell($statement['cash']['out']->negate())];
        $rows[] = ['', $t('cash'), 'cash', 'cash_net', $t('cash_net'), '', self::cell($statement['cash']['net'])];

        return [new Sheet(
            (string) __('analytics.sheet.pnl', [], $locale),
            ['block', 'block_name', 'kind', 'key', 'label', 'ledger_types', 'amount'],
            $rows,
            ['block_name', 'kind', 'key', 'label', 'ledger_types'],
        )];
    }

    /**
     * The P&L subtotals per Dhaka day / Saturday week / month / year, one row per bucket.
     *
     * @param  array<string, mixed>  $series  ProfitAndLoss::series()
     * @return list<Sheet>
     */
    public static function pnlSeries(array $series, string $locale): array
    {
        $lineKeys = array_keys($series['buckets'][0]['lines'] ?? []);
        $totalKeys = ['gross_revenue', 'net_revenue', 'gross_profit', 'profit_after_delivery', 'profit_after_losses', 'net_profit'];
        $rows = array_map(static fn (array $b): array => [
            $b['from'], $b['to'], (string) $b['days'],
            ...array_map(static fn (string $k): string => self::cell($b['lines'][$k]), $lineKeys),
            ...array_map(static fn (string $k): string => self::cell($b['totals'][$k]), $totalKeys),
            self::cell($b['net_margin_percent'] ?? null),
        ], $series['buckets']);

        return [new Sheet(
            (string) __('analytics.sheet.pnl_by.'.$series['granularity'], [], $locale),
            ['from', 'to', 'days', ...$lineKeys, ...$totalKeys, 'net_margin_percent'],
            $rows,
            ['from', 'to'],
        )];
    }

    /**
     * Profitability by product, category or recommendation source — every row of the report.
     * The export needs catalog.view_cost, so cost and profit are always present here; a null
     * figure (no margin on zero revenue) is an empty cell, never zero.
     *
     * @param  array<string, mixed>  $report  Profitability::byProduct()/byCategory()/bySource() + `by`
     * @return list<Sheet>
     */
    public static function profitability(array $report, string $locale): array
    {
        $identity = match ($report['by']) {
            'product' => ['product_id', 'sku', 'name', 'name_bn', 'category_name'],
            'category' => ['category_id', 'parent_id', 'name', 'name_bn', 'products'],
            default => ['source', 'products'],
        };
        $header = [...$identity, 'orders', 'units_sold', 'units_returned', 'units', 'gross_revenue', 'reversals', 'net_revenue', 'cogs', 'gross_profit', 'margin_percent'];

        return [new Sheet(
            (string) __('analytics.sheet.profitability.'.$report['by'], [], $locale),
            $header,
            array_map(static fn (array $row): array => self::pick($row, $header), $report['rows']),
            ['sku', 'name', 'name_bn', 'category_name', 'source'],
        )];
    }

    /**
     * Inventory valuation at cost, one row per variant × location, then the total row.
     *
     * @param  array<string, mixed>  $report  InventoryValuationReport::report()
     * @return list<Sheet>
     */
    public static function inventoryValuation(array $report, string $locale): array
    {
        $header = ['sku', 'name', 'name_bn', 'variant_name', 'category_name', 'location_code', 'location_name', 'on_hand', 'unit_cost', 'value', 'estimated'];
        $rows = array_map(static fn (array $row): array => self::pick($row, $header), $report['rows']);
        $rows[] = ['', (string) __('reports.valuation.total', [], $locale), '', '', '', '', '', self::cell($report['totals']['on_hand']), '', self::cell($report['totals']['value']), ''];

        return [new Sheet(
            (string) __('analytics.sheet.inventory_valuation', [], $locale),
            $header,
            $rows,
            ['sku', 'name', 'name_bn', 'variant_name', 'category_name', 'location_code', 'location_name'],
        )];
    }

    /**
     * Courier performance: the total row, one row per provider, then one per district.
     *
     * @param  array<string, mixed>  $data  CourierPerformanceReport::report()
     * @return list<Sheet>
     */
    public static function couriers(array $data, string $locale): array
    {
        $metrics = ['booked', 'delivered', 'partially_delivered', 'returned', 'cancelled', 'in_flight', 'delivery_rate', 'return_rate', 'average_days_to_deliver', 'average_charge', 'cod_expected', 'cod_collected', 'cod_outstanding'];
        $line = static fn (string $scope, string $code, string $name, string $nameBn, array $row): array => [
            $scope, $code, $name, $nameBn,
            ...array_map(static fn (string $m): string => self::cell($row[$m] ?? null), $metrics),
        ];

        $rows = [$line('total', '', (string) __('reports.couriers.all', [], $locale), '', $data['totals'])];
        foreach ($data['providers'] as $row) {
            $rows[] = $line('provider', $row['provider']['code'], $row['provider']['name'], (string) $row['provider']['name_bn'], $row);
        }
        foreach ($data['districts'] as $row) {
            $rows[] = $line('district', $row['district'] === null ? '' : (string) $row['district']['id'], $row['district']['name'] ?? (string) __('reports.couriers.no_district', [], $locale), (string) ($row['district']['name_bn'] ?? ''), $row);
        }

        return [new Sheet(
            (string) __('analytics.sheet.couriers', [], $locale),
            ['scope', 'code', 'name', 'name_bn', ...$metrics],
            $rows,
            ['code', 'name', 'name_bn'],
        )];
    }

    /** A report value as cell text: Money as its decimal, null as empty. */
    public static function cell(mixed $value): string
    {
        return match (true) {
            $value instanceof Money => $value->toDecimal(),
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function pick(array $row, array $keys): array
    {
        return array_map(static fn (string $key): string => self::cell($row[$key] ?? null), $keys);
    }
}
