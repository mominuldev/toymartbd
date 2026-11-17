<?php

declare(strict_types=1);

namespace App\Domain\Ai\Advisor\Services;

use App\Domain\Ai\Services\AiSettings;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Queries\Dashboard\LowStock;
use App\Domain\Reporting\Queries\Dashboard\PlacedOrders as DashboardPlacedOrders;
use App\Domain\Reporting\Queries\Dashboard\WorkQueues;
use App\Domain\Reporting\Queries\Sales\SalesAnalytics;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The compact facts the Growth advisor sends to the AI — built only from the existing Reporting
 * queries (sales analytics, best sellers, low stock, work queues), for {@see AdvisorWeek} against
 * the same weekdays a week earlier. Every figure is computed here (rule 1); money is the server's
 * decimal string.
 *
 * Figures only: no customer names, phone numbers, addresses or order numbers ever enter the
 * facts (rule 20). Product names are included only while Integrations › AI assistant allows
 * product details; otherwise a product is "Product #id". Cost and profit are never included —
 * the scheduled brief has no viewer, and profit is a money-revealing permission.
 *
 * Product and promotion ids listed here are the only ids a suggested action may target.
 */
final readonly class AdvisorFacts
{
    public function __construct(
        private SalesAnalytics $sales,
        private DashboardPlacedOrders $placed,
        private LowStock $lowStock,
        private WorkQueues $queues,
        private AiSettings $settings,
    ) {}

    /** @return array<string, mixed> */
    public function build(AdvisorWeek $week): array
    {
        $names = $this->settings->share()['products'];
        $key = "ai-advisor:facts:{$week->from}:{$week->to}:".($names ? 'n' : 'x');

        /** @var array<string, mixed> */
        return Cache::remember($key, 300, fn (): array => $this->compute($week, $names));
    }

    /**
     * Product ids and promotion ids the facts mention — what an action may target.
     *
     * @param  array<string, mixed>  $facts
     * @return array{product: list<int>, promotion: list<int>}
     */
    public static function targetIds(array $facts): array
    {
        $products = [];
        foreach (['best_sellers', 'low_stock_items'] as $section) {
            foreach (is_array($facts[$section] ?? null) ? $facts[$section] : [] as $row) {
                if (is_array($row) && is_int($row['product_id'] ?? null)) {
                    $products[] = $row['product_id'];
                }
            }
        }
        $promotions = [];
        foreach (is_array($facts['promotions'] ?? null) ? $facts['promotions'] : [] as $row) {
            if (is_array($row) && is_int($row['promotion_id'] ?? null)) {
                $promotions[] = $row['promotion_id'];
            }
        }

        return ['product' => array_values(array_unique($products)), 'promotion' => array_values(array_unique($promotions))];
    }

    /** @return array<string, mixed> */
    private function compute(AdvisorWeek $week, bool $names): array
    {
        $range = DateRange::custom($week->from, $week->to);
        $before = DateRange::custom($week->compareFrom, $week->compareTo);
        $now = $this->sales->report($range, ReportGranularity::Day, false);
        $prev = $this->sales->report($before, ReportGranularity::Day, false);
        $thisWeek = self::summary($now['summary']);
        $lastWeek = self::summary($prev['summary']);

        return [
            'currency' => 'BDT (৳)',
            'period' => [
                'this_week' => ['from' => $week->from, 'to' => $week->to, 'days' => $week->days(), 'complete_week' => $week->complete()],
                'compared_with' => ['from' => $week->compareFrom, 'to' => $week->compareTo, 'note' => 'the same weekdays one week earlier'],
            ],
            'sales' => [
                'this_week' => $thisWeek,
                'last_week' => $lastWeek,
                'change_percent' => [
                    'placed_value' => Rates::moneyDelta(Money::of($thisWeek['placed_value_after_cancellations']), Money::of($lastWeek['placed_value_after_cancellations'])),
                    'placed_orders' => Rates::countDelta($thisWeek['placed_orders_after_cancellations'], $lastWeek['placed_orders_after_cancellations']),
                    'recognised_revenue' => Rates::moneyDelta(Money::of($thisWeek['recognised_revenue']), Money::of($lastWeek['recognised_revenue'])),
                    'delivered_orders' => Rates::countDelta($thisWeek['delivered_orders'], $lastWeek['delivered_orders']),
                ],
                'notes' => 'placed = orders placed (demand, not revenue); recognised_revenue = revenue on delivery from the ledger. Conversion rate is not measured.',
            ],
            'sales_by_day' => self::byDay($now['trend'], $prev['trend']),
            'channels' => self::mix($now['channels'], 'source'),
            'payment_methods' => self::mix($now['payment_methods'], 'method'),
            'categories' => self::categories($now['categories'], $names),
            'best_sellers' => $this->bestSellers($range, $names),
            'promotions' => self::promotions($now['discounts']['promotions'] ?? [], $names),
            'coupons' => self::coupons($now['discounts']['coupons'] ?? []),
            'discounts' => [
                'orders_with_discount' => $now['discounts']['orders_with_discount'],
                'discount_total' => self::money($now['discounts']['discount_total']),
            ],
            'work_queues' => $this->queues(),
            'catalog' => ['active_products_without_bangla_description' => self::missingBangla()],
        ] + $this->stock($range, $names);
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array{placed_orders: int, placed_value: string, cancelled_orders: int, cancel_rate_percent: string|null, placed_orders_after_cancellations: int, placed_value_after_cancellations: string, average_order_value: string|null, units: int, delivered_orders: int, delivered_value: string, recognised_revenue: string, customers: int, new_customers: int, returning_customers: int}
     */
    private static function summary(array $s): array
    {
        return [
            'placed_orders' => (int) $s['placed']['orders'],
            'placed_value' => self::money($s['placed']['value']) ?? '0.00',
            'cancelled_orders' => (int) $s['placed']['cancelled_orders'],
            'cancel_rate_percent' => $s['placed']['cancel_rate'],
            'placed_orders_after_cancellations' => (int) $s['placed']['net_orders'],
            'placed_value_after_cancellations' => self::money($s['placed']['net_value']) ?? '0.00',
            'average_order_value' => self::money($s['placed']['aov']),
            'units' => (int) $s['placed']['units'],
            'delivered_orders' => (int) $s['delivered']['orders'],
            'delivered_value' => self::money($s['delivered']['value']) ?? '0.00',
            'recognised_revenue' => self::money($s['recognised']['net_revenue']) ?? '0.00',
            'customers' => (int) $s['customers']['customers'],
            'new_customers' => (int) $s['customers']['new'],
            'returning_customers' => (int) $s['customers']['returning'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $now
     * @param  list<array<string, mixed>>  $prev
     * @return list<array<string, mixed>>
     */
    private static function byDay(array $now, array $prev): array
    {
        $rows = [];
        foreach ($now as $i => $b) {
            $p = $prev[$i] ?? null;
            $rows[] = [
                'date' => $b['from'],
                'weekday' => CarbonImmutable::parse((string) $b['from'])->format('D'),
                'placed_orders' => $b['placed_orders'],
                'placed_value' => self::money($b['placed_value']),
                'cancelled_orders' => $b['cancelled_orders'],
                'delivered_orders' => $b['delivered_orders'],
                'last_week_placed_orders' => $p['placed_orders'] ?? null,
                'last_week_placed_value' => $p === null ? null : self::money($p['placed_value']),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function mix(array $rows, string $key): array
    {
        return array_map(static fn (array $r): array => [
            $key => $r[$key],
            'orders' => $r['orders'],
            'value' => self::money($r['value']),
            'orders_percent' => $r['orders_percent'],
        ], array_slice($rows, 0, 6));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function categories(array $rows, bool $names): array
    {
        $out = [];
        foreach (array_slice($rows, 0, 6) as $r) {
            $out[] = [
                'category' => $names ? ($r['name'] ?? 'Uncategorised') : 'Category #'.($r['category_id'] ?? 0),
                'units_delivered' => $r['units'] ?? null,
                'net_revenue' => self::money($r['net_revenue'] ?? null),
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function bestSellers(DateRange $range, bool $names): array
    {
        $rows = $this->placed->bestSellers($range, 8)['rows'];
        $ids = array_values(array_filter(array_column($rows, 'product_id'), 'is_int'));
        $bangla = $ids === [] ? [] : DB::table('products')->whereIn('id', $ids)
            ->select(['id', 'description_bn'])->get()
            ->mapWithKeys(static fn (object $p): array => [(int) $p->id => is_string($p->description_bn) && trim($p->description_bn) !== ''])->all();

        $out = [];
        foreach ($rows as $r) {
            $id = $r['product_id'];
            $out[] = [
                'product_id' => $id,
                'name' => $names ? $r['name'] : 'Product #'.($id ?? 0),
                'units_sold' => $r['units'],
                'value' => self::money($r['value']),
                'has_bangla_description' => $id === null ? null : ($bangla[$id] ?? false),
            ];
        }

        return $out;
    }

    /** @return array{low_stock: array{low: int, out: int}|null, low_stock_items: list<array<string, mixed>>} */
    private function stock(DateRange $range, bool $names): array
    {
        $low = $this->lowStock->summary(8);
        if ($low === null) {
            return ['low_stock' => null, 'low_stock_items' => []];
        }
        [$start, $end] = $range->utcBounds();
        $variantIds = array_column($low['rows'], 'variant_id');
        $sold = $variantIds === [] ? [] : DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('order_items.variant_id', $variantIds)
            ->where('orders.placed_at', '>=', $start)->where('orders.placed_at', '<', $end)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.variant_id')
            ->selectRaw('order_items.variant_id as variant_id, SUM(order_items.quantity) as units')
            ->pluck('units', 'variant_id')->all();

        $items = [];
        foreach ($low['rows'] as $r) {
            $items[] = [
                'product_id' => $r['product_id'],
                'name' => $names ? trim($r['name'].($r['variant_name'] !== null ? ' · '.$r['variant_name'] : '')) : 'Product #'.$r['product_id'],
                'available' => $r['available'],
                'reorder_point' => $r['reorder_point'],
                'units_sold_this_week' => (int) ($sold[$r['variant_id']] ?? 0),
            ];
        }

        return ['low_stock' => ['low' => $low['low'], 'out' => $low['out']], 'low_stock_items' => $items];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function promotions(array $rows, bool $names): array
    {
        return array_map(static fn (array $r): array => [
            'promotion_id' => (int) $r['promotion_id'],
            'name' => $names ? $r['name'] : 'Promotion #'.$r['promotion_id'],
            'orders' => $r['orders'],
            'discount_given' => self::money($r['discount']),
        ], array_slice($rows, 0, 5));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function coupons(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'orders' => $r['orders'],
            'discount_given' => self::money($r['discount']),
        ], array_slice($rows, 0, 5));
    }

    /** @return array<string, int> */
    private function queues(): array
    {
        $out = [];
        foreach ($this->queues->attention(['verification', 'failed_bookings', 'unmapped_areas', 'submitted']) as $name => $q) {
            $out[match ($name) {
                'verification' => 'orders_waiting_verification',
                'failed_bookings' => 'failed_courier_bookings',
                'unmapped_areas' => 'bookings_blocked_by_unmapped_area',
                default => 'bookings_submitted_awaiting_confirmation',
            }] = $q['count'];
        }

        return $out;
    }

    private static function missingBangla(): int
    {
        return DB::table('products')->where('status', ProductStatus::Active->value)
            ->where(static fn ($q) => $q->whereNull('description_bn')->orWhere('description_bn', ''))
            ->count();
    }

    private static function money(mixed $value): ?string
    {
        return $value instanceof Money ? $value->toDecimal() : (is_string($value) ? $value : null);
    }
}
