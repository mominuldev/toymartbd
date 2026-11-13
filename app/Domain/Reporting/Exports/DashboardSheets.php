<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Exports;

use App\Support\DhakaDate;

/**
 * The admin dashboard ({@see \App\Domain\Reporting\Queries\Dashboard\DashboardReport::build()})
 * as export sections, streamed through {@see Spreadsheet} like the sales export.
 *
 * Same numbers as the JSON: every cell is read from the very array DashboardResource
 * serialises, money as its 2-place decimal string (rule 6), percentages as the report's strings.
 *
 * A block the viewer may not see is **left out** — its rows, columns and sheets are absent,
 * never written as zero or blank (a zero would be a false statement). Column keys are stable
 * machine keys like the other report exports; labels and sheet titles are in the requested
 * locale; numerals are Western in both (rule 36). Dates are Dhaka dates (rule 11).
 *
 * PII-free by construction: the activity feed (staff names, order numbers) and the
 * needs-attention queues (live work items, not report figures) are not exported, and no row
 * carries a customer name, phone or address.
 *
 *   sheet          needs (the JSON block it comes from)
 *   summary        always the range rows; then each KPI only with its block
 *   trend          orders.view (placed series) and/or finance.view (ledger series) and/or
 *                  customers.view (new customers) — one column group per visible block
 *   status         orders.view (live, not range-bound — labelled "now")
 *   categories     finance.view (recognised item revenue of delivered orders)
 *   best sellers   orders.view (placed, non-cancelled orders)
 *   low stock      inventory.view (live — labelled "now")
 */
final class DashboardSheets
{
    /**
     * @param  array<string, mixed>  $report  DashboardReport::build()
     * @return list<Sheet>
     */
    public static function build(array $report, string $locale, ?string $exportedAt = null): array
    {
        $t = static fn (string $key): string => (string) __('dashboard.'.$key, [], $locale);
        $kpis = $report['kpis'];

        $sheets = [new Sheet($t('sheet.summary'), ['key', 'metric', 'value', 'previous', 'change_percent'], self::summary($report, $t, $exportedAt ?? DhakaDate::instant()->format('Y-m-d H:i')))];

        $trend = self::trend($report);
        if ($trend !== null) {
            $sheets[] = new Sheet($t('sheet.trend'), $trend['header'], $trend['rows']);
        }

        if ($report['status'] !== null) {
            $rows = [];
            foreach ($report['status']['counts'] as $status => $count) {
                $rows[] = [(string) $status, $t('status.'.$status), (string) $count];
            }
            $sheets[] = new Sheet($t('sheet.status'), ['status', 'label', 'orders'], $rows);
        }

        if ($report['categories'] !== null) {
            $rows = [];
            foreach ($report['categories']['slices'] as $slice) {
                $rows[] = [
                    $slice['other'] ? 'other' : 'category',
                    ReportSheets::cell($slice['category_id']),
                    $slice['other'] ? $t('other_categories') : ($slice['name'] ?? $t('no_category')),
                    ReportSheets::cell($slice['name_bn']),
                    ReportSheets::cell($slice['amount']),
                    ReportSheets::cell($slice['share_percent']),
                ];
            }
            $rows[] = ['total', '', $t('total'), '', ReportSheets::cell($report['categories']['total']), ''];
            $sheets[] = new Sheet($t('sheet.categories'), ['row', 'category_id', 'name', 'name_bn', 'recognised_item_revenue', 'share_percent'], $rows);
        }

        if ($report['best_sellers'] !== null) {
            $rows = [];
            foreach ($report['best_sellers']['rows'] as $index => $row) {
                $rows[] = [(string) ($index + 1), ReportSheets::cell($row['product_id']), $row['name'], ReportSheets::cell($row['name_bn']), $row['sku'], (string) $row['units'], ReportSheets::cell($row['value'])];
            }
            $rows[] = ['total', (string) $report['best_sellers']['products'], $t('all_products'), '', '', (string) $report['best_sellers']['units'], ReportSheets::cell($report['best_sellers']['value'])];
            $sheets[] = new Sheet($t('sheet.best_sellers'), ['rank', 'product_id', 'name', 'name_bn', 'sku', 'units_placed', 'value_placed'], $rows, ['sku']);
        }

        if ($report['low_stock'] !== null && $kpis['low_stock'] !== null) {
            $rows = array_map(static fn (array $row): array => [
                (string) $row['variant_id'],
                (string) $row['product_id'],
                $row['name'],
                ReportSheets::cell($row['name_bn']),
                ReportSheets::cell($row['variant_name']),
                $row['sku'],
                (string) $row['on_hand'],
                (string) $row['reserved'],
                (string) $row['available'],
                (string) $row['reorder_point'],
            ], $report['low_stock']['rows']);
            $sheets[] = new Sheet($t('sheet.low_stock'), ['variant_id', 'product_id', 'name', 'name_bn', 'variant_name', 'sku', 'on_hand', 'reserved', 'available', 'reorder_point'], $rows, ['sku']);
        }

        return $sheets;
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  callable(string): string  $t
     * @return list<list<string>>
     */
    private static function summary(array $report, callable $t, string $exportedAt): array
    {
        $kpis = $report['kpis'];
        $line = static fn (string $key, mixed $value, mixed $previous = null, mixed $change = null): array => [$key, $t('metric.'.$key), ReportSheets::cell($value), ReportSheets::cell($previous), ReportSheets::cell($change)];

        $rows = [
            ['range', $t('metric.range'), $report['range']['from'].' – '.$report['range']['to'], $report['previous']['from'].' – '.$report['previous']['to'], ''],
            ['granularity', $t('metric.granularity'), $t('granularity.'.$report['range']['granularity']), '', ''],
            ['exported_at', $t('metric.exported_at'), $exportedAt, '', ''],
        ];

        if (($placed = $kpis['orders_placed']) !== null) {
            $rows[] = $line('orders_placed', $placed['count'], $placed['previous_count'], $placed['count_delta_percent']);
            $rows[] = $line('placed_value', $placed['value'], $placed['previous_value'], $placed['value_delta_percent']);
        }
        if (($aov = $kpis['average_order_value']) !== null) {
            $rows[] = $line('average_order_value', $aov['value'], $aov['previous'], $aov['delta_percent']);
        }
        if (($revenue = $kpis['recognised_revenue']) !== null) {
            $rows[] = $line('recognised_revenue', $revenue['value'], $revenue['previous'], $revenue['delta_percent']);
        }
        if (($gross = $kpis['gross_profit']) !== null) {
            $rows[] = $line('gross_profit', $gross['value'], $gross['previous'], $gross['delta_percent']);
            $rows[] = $line('gross_margin_percent', $gross['margin_percent']);
        }
        if (($net = $kpis['net_profit']) !== null) {
            $rows[] = $line('net_profit', $net['value'], $net['previous'], $net['delta_percent']);
            $rows[] = $line('net_margin_percent', $net['margin_percent']);
        }
        if (($cash = $kpis['cash_collected']) !== null) {
            $rows[] = $line('cash_collected', $cash['value'], $cash['previous'], $cash['delta_percent']);
        }
        if (($customers = $kpis['customers']) !== null) {
            $rows[] = $line('customers', $customers['customers'], $customers['previous_customers']);
            $rows[] = $line('new_customers', $customers['new'], $customers['previous_new'], $customers['new_delta_percent']);
            $rows[] = $line('returning_customers', $customers['returning'], $customers['previous_returning']);
        }
        if (($low = $kpis['low_stock']) !== null) {
            $rows[] = $line('low_stock', $low['low']);
            $rows[] = $line('out_of_stock', $low['out']);
        }
        if ($report['status'] !== null) {
            $rows[] = $line('open_orders', $report['status']['open']['count']);
            $rows[] = $line('open_orders_value', $report['status']['open']['value']);
        }

        return $rows;
    }

    /**
     * One row per bucket; a column group per block the viewer may see. Null when none is.
     *
     * @param  array<string, mixed>  $report
     * @return array{header: list<string>, rows: list<list<string>>}|null
     */
    private static function trend(array $report): ?array
    {
        $kpis = $report['kpis'];
        $placed = $report['trend'];
        $finance = $kpis['recognised_revenue'] !== null;
        $customers = $kpis['customers'] !== null;
        if ($placed === null && ! $finance && ! $customers) {
            return null;
        }

        // Bucket keys are the bucket's first Dhaka day; every visible series uses the same keys.
        $series = static function (?array $kpi, string $field = 'value'): array {
            $out = [];
            foreach ($kpi['sparkline'] ?? [] as $point) {
                $out[$point['key']] = $point[$field];
            }

            return $out;
        };
        $revenue = $series($kpis['recognised_revenue']);
        $gross = $series($kpis['gross_profit']);
        $net = $series($kpis['net_profit']);
        $cash = $series($kpis['cash_collected']);
        $new = $series($kpis['customers']);

        $placedByKey = [];
        foreach ($placed['buckets'] ?? [] as $bucket) {
            $placedByKey[$bucket['key']] = $bucket;
        }
        $keys = array_values(array_unique([...array_keys($placedByKey), ...array_keys($revenue), ...array_keys($new)]));
        sort($keys);

        $header = ['bucket_start'];
        if ($placed !== null) {
            array_push($header, 'bucket_end', 'placed_orders', 'placed_value', 'units_placed', 'previous_bucket_start', 'previous_placed_orders', 'previous_placed_value', 'previous_units_placed');
        }
        if ($finance) {
            array_push($header, 'recognised_revenue', 'gross_profit', 'net_profit', 'cash_collected');
        }
        if ($customers) {
            $header[] = 'new_customers';
        }

        $rows = [];
        foreach ($keys as $key) {
            $row = [$key];
            if ($placed !== null) {
                $b = $placedByKey[$key] ?? null;
                array_push(
                    $row,
                    ReportSheets::cell($b['to'] ?? null),
                    ReportSheets::cell($b['placed_count'] ?? null),
                    ReportSheets::cell($b['placed_value'] ?? null),
                    ReportSheets::cell($b['units'] ?? null),
                    ReportSheets::cell($b['previous_key'] ?? null),
                    ReportSheets::cell($b['previous_placed_count'] ?? null),
                    ReportSheets::cell($b['previous_placed_value'] ?? null),
                    ReportSheets::cell($b['previous_units'] ?? null),
                );
            }
            if ($finance) {
                array_push($row, ReportSheets::cell($revenue[$key] ?? null), ReportSheets::cell($gross[$key] ?? null), ReportSheets::cell($net[$key] ?? null), ReportSheets::cell($cash[$key] ?? null));
            }
            if ($customers) {
                $row[] = ReportSheets::cell($new[$key] ?? null);
            }
            $rows[] = $row;
        }

        return ['header' => $header, 'rows' => $rows];
    }
}
