<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Support\Money;

/**
 * Turns report arrays (SalesAnalytics, ProfitAndLoss::statement, CourierPerformanceReport)
 * into export sections. Headers are stable machine keys like the other report CSVs; labels
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
     * The P&L statement as one section, one row per line (the shape of the P&L CSV).
     *
     * @param  array<string, mixed>  $statement  ProfitAndLoss::statement()
     * @return list<Sheet>
     */
    public static function pnl(array $statement, string $locale): array
    {
        $rows = [];
        foreach ($statement['blocks'] as $block) {
            foreach ($block['rows'] as $row) {
                $rows[] = [(string) $block['number'], (string) __('reports.pnl.block.'.$block['key'], [], $locale), $row['kind'], $row['key'], (string) __('reports.pnl.line.'.$row['key'], [], $locale), self::cell($row['amount'])];
            }
        }
        foreach ($statement['expenses_by_category'] as $category) {
            $rows[] = ['', (string) __('reports.pnl.expenses_by_category', [], $locale), 'category', (string) $category['category_id'], $category['name'], self::cell($category['amount'])];
        }
        $rows[] = ['', (string) __('reports.pnl.cash', [], $locale), 'cash', 'cash_in', (string) __('reports.pnl.cash_in', [], $locale), self::cell($statement['cash']['in'])];
        $rows[] = ['', (string) __('reports.pnl.cash', [], $locale), 'cash', 'cash_out', (string) __('reports.pnl.cash_out', [], $locale), self::cell($statement['cash']['out']->negate())];
        $rows[] = ['', (string) __('reports.pnl.cash', [], $locale), 'cash', 'cash_net', (string) __('reports.pnl.cash_net', [], $locale), self::cell($statement['cash']['net'])];

        return [new Sheet((string) __('analytics.sheet.pnl', [], $locale), ['block', 'block_name', 'kind', 'key', 'label', 'amount'], $rows)];
    }

    /**
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

        return [new Sheet((string) __('analytics.sheet.couriers', [], $locale), ['scope', 'code', 'name', 'name_bn', ...$metrics], $rows)];
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
