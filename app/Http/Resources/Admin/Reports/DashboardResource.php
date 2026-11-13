<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use App\Http\Resources\Admin\CategoryResource;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The admin dashboard (DashboardReport::build). Every figure is the server's; money is a
 * decimal string (rule 6), percentages are strings to 2 dp or null when the comparison base
 * is zero. A block the viewer may not see is `null` (see `visibility`), never a zero.
 *
 * Labels matter: `orders_placed` is demand (orders placed, by Dhaka day of placed_at), never
 * revenue; `recognised_revenue`, `gross_profit` and `net_profit` are the P&L's, recognised on
 * delivery; `cash_collected` is COD remitted (rule 10).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('Dashboard')]
final class DashboardResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var array{preset: 'today'|'7d'|'30d'|'this_month'|'last_month'|'custom', from: string, to: string, days: int, granularity: 'day'|'week'|'month'} */
            'range' => $r['range'],
            /** @var array{from: string, to: string, days: int} The comparison range the deltas are against. */
            'previous' => $r['previous'],
            /** @var array{orders: bool, finance: bool, customers: bool, inventory: bool} */
            'visibility' => $r['visibility'],
            /**
             * @var array{
             *     orders_placed: array{count: int, previous_count: int, count_delta_percent: string|null, value: string, previous_value: string, value_delta_percent: string|null, sparkline: list<array{key: string, count: int, value: string}>}|null,
             *     average_order_value: array{value: string|null, previous: string|null, delta_percent: string|null, sparkline: list<array{key: string, value: string|null}>}|null,
             *     recognised_revenue: array{value: string, previous: string, delta_percent: string|null, source: 'ledger'|'summaries'|'mixed', sparkline: list<array{key: string, value: string}>}|null,
             *     gross_profit: array{value: string, previous: string, delta_percent: string|null, margin_percent: string|null, sparkline: list<array{key: string, value: string}>}|null,
             *     net_profit: array{value: string, previous: string, delta_percent: string|null, margin_percent: string|null, sparkline: list<array{key: string, value: string}>}|null,
             *     cash_collected: array{value: string, previous: string, delta_percent: string|null, sparkline: list<array{key: string, value: string}>}|null,
             *     customers: array{customers: int, new: int, returning: int, previous_customers: int, previous_new: int, previous_returning: int, new_delta_percent: string|null, sparkline: list<array{key: string, value: int}>}|null,
             *     low_stock: array{low: int, out: int}|null
             * }
             */
            'kpis' => self::plain($r['kpis']),
            /**
             * Live order counts by status (not range-bound) and the open pipeline. Needs orders.view.
             *
             * @var array{counts: array{pending: int, on_hold: int, confirmed: int, processing: int, ready_to_ship: int, shipped: int, delivered: int, completed: int, cancelled: int, returned: int, partially_returned: int}, open: array{count: int, value: string}}|null
             */
            'status' => self::plain($r['status']),
            /**
             * Placed value / orders / units per bucket against the comparison range (aligned by
             * position), and recognised revenue per bucket when finance is visible (else null).
             *
             * @var array{granularity: 'day'|'week'|'month', placed_value: string, previous_placed_value: string, recognised_revenue: string|null, buckets: list<array{key: string, from: string, to: string, placed_value: string, placed_count: int, units: int, previous_key: string|null, previous_placed_value: string|null, previous_placed_count: int|null, previous_units: int|null, recognised_revenue: string|null}>}|null
             */
            'trend' => self::plain($r['trend']),
            /**
             * Recognised item revenue of the orders delivered in the range, by top-level category.
             *
             * @var array{total: string, slices: list<array{category_id: int|null, name: string|null, name_bn: string|null, other: bool, amount: string, share_percent: string|null}>}|null
             */
            'categories' => self::plain($r['categories']),
            /** @var array{rows: list<array{product_id: int|null, name: string, name_bn: string|null, sku: string, image_url: string|null, units: int, value: string}>, units: int, products: int, value: string}|null */
            'best_sellers' => $r['best_sellers'] === null ? null : [
                'rows' => array_map(static fn (array $row): array => [
                    'product_id' => $row['product_id'],
                    'name' => $row['name'],
                    'name_bn' => $row['name_bn'],
                    'sku' => $row['sku'],
                    'image_url' => CategoryResource::publicUrl($row['image_path']),
                    'units' => $row['units'],
                    'value' => $row['value']->toDecimal(),
                ], $r['best_sellers']['rows']),
                'units' => $r['best_sellers']['units'],
                'products' => $r['best_sellers']['products'],
                'value' => $r['best_sellers']['value']->toDecimal(),
            ],
            /** @var array{location_id: int, low: int, out: int, rows: list<array{variant_id: int, product_id: int, name: string, name_bn: string|null, variant_name: string|null, sku: string, on_hand: int, reserved: int, available: int, reorder_point: int}>}|null */
            'low_stock' => $r['low_stock'],
            /**
             * Live queues the viewer may act on — count and the oldest item's age; only the queues
             * the viewer's permissions allow are listed.
             *
             * @var list<array{key: 'verification'|'failed_bookings'|'unmapped_areas'|'submitted'|'cod_variances'|'expenses'|'refunds'|'low_stock', count: int, oldest_at: string|null}>
             */
            'attention' => array_map(static fn (array $item): array => [
                'key' => $item['key'],
                'count' => $item['count'],
                'oldest_at' => self::iso($item['oldest_at']),
            ], $r['attention']),
            /**
             * Newest order timeline events, structured only — no notes, descriptions or customer data.
             *
             * @var list<array{id: int, type: string, field: string|null, from: string|null, to: string|null, order_id: int, order_number: string, actor: string|null, at: string}>|null
             */
            'activity' => $r['activity'] === null ? null : array_map(static fn (array $event): array => [...$event, 'at' => (string) self::iso($event['at'])], $r['activity']),
        ];
    }

    private static function iso(mixed $at): ?string
    {
        return $at instanceof CarbonImmutable ? $at->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
