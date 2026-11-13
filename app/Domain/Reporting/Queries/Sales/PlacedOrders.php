<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Sales;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Orders **placed** in a range — demand, not revenue. Read-only.
 *
 * An order belongs to the Dhaka day of `placed_at` (rule 11). "Placed value" is the
 * order's grand_total at checkout; it is NOT revenue (revenue is recognised on delivery,
 * rule 10, and comes from the ledger in {@see SalesAnalytics}). Orders later cancelled are
 * counted and valued separately, and every mix (payment method, channel, discount) is over
 * the placed orders that were not cancelled, so a cancelled order never inflates demand.
 *
 * One streamed pass over the range's orders on the `orders(placed_at)` index; bucketing is
 * done here in PHP because a Dhaka day is not a UTC day and the SQL to shift it differs
 * between MySQL and SQLite.
 */
final readonly class PlacedOrders
{
    /** Outcome groups for "Orders by outcome" (B · 24 241:30584), by the order's status now. */
    public const array OUTCOMES = [
        'delivered' => [OrderStatus::Delivered, OrderStatus::Completed],
        'shipped' => [OrderStatus::Shipped],
        'open' => [OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip],
        'cancelled' => [OrderStatus::Cancelled],
        'returned' => [OrderStatus::Returned, OrderStatus::PartiallyReturned],
    ];

    public const string NO_METHOD = 'unknown';

    /**
     * @return array{
     *     buckets: array<string, array{orders: int, value: Money, units: int, cancelled: int, outcomes: array<string, int>}>,
     *     totals: array{orders: int, value: Money, cancelled_orders: int, cancelled_value: Money, net_orders: int, net_value: Money, units: int},
     *     payment_methods: array<string, array{orders: int, value: Money}>,
     *     channels: array<string, array{orders: int, value: Money}>,
     *     discounts: array{orders_with_discount: int, value_with_discount: Money, value_without_discount: Money, discount_total: Money, coupon_discount: Money, promotion_discount: Money, shipping_discount: Money, coupons: array<string, array{orders: int, discount: Money, value: Money}>},
     *     customers: array<int, int>,
     * }
     */
    public function collect(DateRange $range, ?ReportGranularity $granularity): array
    {
        [$start, $end] = $range->utcBounds();
        $buckets = [];
        if ($granularity !== null) {
            foreach ($range->buckets($granularity) as $bucket) {
                $buckets[$bucket['key']] = ['orders' => 0, 'value' => Money::zero(), 'units' => 0, 'cancelled' => 0, 'outcomes' => array_fill_keys(array_keys(self::OUTCOMES), 0)];
            }
        }
        $outcomeOf = [];
        foreach (self::OUTCOMES as $outcome => $statuses) {
            foreach ($statuses as $status) {
                $outcomeOf[$status->value] = $outcome;
            }
        }

        $totals = ['orders' => 0, 'value' => Money::zero(), 'cancelled_orders' => 0, 'cancelled_value' => Money::zero(), 'net_orders' => 0, 'net_value' => Money::zero(), 'units' => 0];
        $methods = [];
        $channels = [];
        $discounts = [
            'orders_with_discount' => 0, 'value_with_discount' => Money::zero(), 'value_without_discount' => Money::zero(),
            'discount_total' => Money::zero(), 'coupon_discount' => Money::zero(), 'promotion_discount' => Money::zero(), 'shipping_discount' => Money::zero(),
            'coupons' => [],
        ];
        $customers = [];

        $bucketOf = $granularity === null ? null : self::bucketer($range, $granularity);

        $rows = DB::table('orders')
            ->where('placed_at', '>=', $start)
            ->where('placed_at', '<', $end)
            ->select(['id', 'customer_id', 'status', 'source', 'payment_method', 'placed_at', 'grand_total', 'discount_total', 'coupon_code', 'coupon_discount', 'promotion_discount', 'shipping_discount'])
            ->selectSub(DB::table('order_items')->whereColumn('order_items.order_id', 'orders.id')->selectRaw('COALESCE(SUM(quantity), 0)'), 'units')
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $value = ReportMoney::of($row->grand_total);
            $status = (string) $row->status;
            $cancelled = $status === OrderStatus::Cancelled->value;
            $units = (int) $row->units;

            $totals['orders']++;
            $totals['value'] = $totals['value']->plus($value);

            if ($granularity !== null && is_scalar($row->placed_at)) {
                assert($bucketOf !== null);
                $key = $bucketOf((string) $row->placed_at);
                if (isset($buckets[$key])) {
                    $outcome = $outcomeOf[$status] ?? 'open';
                    $buckets[$key]['outcomes'][$outcome]++;
                    if ($cancelled) {
                        $buckets[$key]['cancelled']++;
                    } else {
                        $buckets[$key]['orders']++;
                        $buckets[$key]['value'] = $buckets[$key]['value']->plus($value);
                        $buckets[$key]['units'] += $units;
                    }
                }
            }

            if ($cancelled) {
                $totals['cancelled_orders']++;
                $totals['cancelled_value'] = $totals['cancelled_value']->plus($value);

                continue;
            }

            $totals['net_orders']++;
            $totals['net_value'] = $totals['net_value']->plus($value);
            $totals['units'] += $units;
            $customers[(int) $row->customer_id] = ($customers[(int) $row->customer_id] ?? 0) + 1;

            $method = is_string($row->payment_method) && $row->payment_method !== '' ? $row->payment_method : self::NO_METHOD;
            $methods[$method] ??= ['orders' => 0, 'value' => Money::zero()];
            $methods[$method]['orders']++;
            $methods[$method]['value'] = $methods[$method]['value']->plus($value);

            $source = (string) $row->source;
            $channels[$source] ??= ['orders' => 0, 'value' => Money::zero()];
            $channels[$source]['orders']++;
            $channels[$source]['value'] = $channels[$source]['value']->plus($value);

            $discount = ReportMoney::of($row->discount_total);
            $shippingDiscount = ReportMoney::of($row->shipping_discount);
            if (! $discount->isZero() || ! $shippingDiscount->isZero()) {
                $discounts['orders_with_discount']++;
                $discounts['value_with_discount'] = $discounts['value_with_discount']->plus($value);
            } else {
                $discounts['value_without_discount'] = $discounts['value_without_discount']->plus($value);
            }
            $discounts['discount_total'] = $discounts['discount_total']->plus($discount);
            $discounts['coupon_discount'] = $discounts['coupon_discount']->plus(ReportMoney::of($row->coupon_discount));
            $discounts['promotion_discount'] = $discounts['promotion_discount']->plus(ReportMoney::of($row->promotion_discount));
            $discounts['shipping_discount'] = $discounts['shipping_discount']->plus($shippingDiscount);
            if (is_string($row->coupon_code) && $row->coupon_code !== '') {
                $code = mb_strtoupper($row->coupon_code);
                $discounts['coupons'][$code] ??= ['orders' => 0, 'discount' => Money::zero(), 'value' => Money::zero()];
                $discounts['coupons'][$code]['orders']++;
                $discounts['coupons'][$code]['discount'] = $discounts['coupons'][$code]['discount']->plus(ReportMoney::of($row->coupon_discount));
                $discounts['coupons'][$code]['value'] = $discounts['coupons'][$code]['value']->plus($value);
            }
        }

        return [
            'buckets' => $buckets,
            'totals' => $totals,
            'payment_methods' => $methods,
            'channels' => $channels,
            'discounts' => $discounts,
            'customers' => $customers,
        ];
    }

    /**
     * A memoised "UTC timestamp → bucket key" for a range: Dhaka (UTC+6, no DST) has a whole-hour
     * offset, so every timestamp in one UTC hour lands on the same Dhaka day and the Carbon work
     * is done once per hour instead of once per order. A zone with a part-hour offset falls back
     * to one conversion per minute — still exact.
     *
     * @return callable(string): string
     */
    public static function bucketer(DateRange $range, ReportGranularity $granularity): callable
    {
        $offset = CarbonImmutable::now(DhakaDate::zone())->getOffset();
        $length = $offset % 3600 === 0 ? 13 : 16; // 'Y-m-d H' or 'Y-m-d H:i'
        $cache = [];

        return static function (string $utc) use ($range, $granularity, $length, &$cache): string {
            $slot = substr($utc, 0, $length);
            if (! isset($cache[$slot])) {
                $day = DhakaDate::dayOf(CarbonImmutable::parse($utc, 'UTC'));
                $cache[$slot] = $range->bucketOf($day, $granularity);
            }

            return $cache[$slot];
        };
    }

    /**
     * Repeat purchase among the customers who placed a (non-cancelled) order in the range:
     * a customer is **returning** when they had a non-cancelled order placed before the range,
     * or placed two or more in it. `repeat_rate` = returning / customers.
     *
     * @param  array<int, int>  $customers  customer id => non-cancelled orders in the range
     * @return array{customers: int, returning: int, new: int, repeat_rate: string|null}
     */
    public function repeatPurchase(DateRange $range, array $customers): array
    {
        [$start] = $range->utcBounds();
        $returning = [];
        foreach ($customers as $id => $count) {
            if ($count >= 2) {
                $returning[$id] = true;
            }
        }

        foreach (array_chunk(array_keys($customers), 1000) as $chunk) {
            DB::table('orders')
                ->whereIn('customer_id', $chunk)
                ->where('placed_at', '<', $start)
                ->where('status', '!=', OrderStatus::Cancelled->value)
                ->distinct()
                ->pluck('customer_id')
                ->each(static function (mixed $id) use (&$returning): void {
                    $returning[(int) $id] = true;
                });
        }

        $total = count($customers);
        $repeat = count($returning);

        return ['customers' => $total, 'returning' => $repeat, 'new' => $total - $repeat, 'repeat_rate' => Rates::percent($repeat, $total)];
    }

    /**
     * Recommendation placements on the lines of non-cancelled orders placed in the range
     * (`order_items.recommendation_source`, rule 25d) — what each placement put in a basket.
     * Lines without a source are `none`.
     *
     * @return array<string, array{lines: int, units: int, orders: int, value: Money}>
     */
    public function recommendationLines(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.recommendation_source')
            ->select('order_items.recommendation_source')
            ->selectRaw('COUNT(*) as line_count, SUM(order_items.quantity) as units, COUNT(DISTINCT order_items.order_id) as order_count, SUM(order_items.line_total) as value')
            ->get();

        $sources = [];
        foreach ($rows as $row) {
            $key = is_string($row->recommendation_source) && $row->recommendation_source !== '' ? $row->recommendation_source : SalesAnalytics::NO_SOURCE;
            $sources[$key] = [
                'lines' => (int) $row->line_count,
                'units' => (int) $row->units,
                'orders' => (int) $row->order_count,
                'value' => ReportMoney::of($row->value),
            ];
        }

        return $sources;
    }

    /**
     * Promotions applied to non-cancelled orders placed in the range (`promotion_usages`).
     *
     * @return list<array{promotion_id: int, name: string, name_bn: string|null, orders: int, discount: Money}>
     */
    public function promotions(DateRange $range, int $limit = 10): array
    {
        [$start, $end] = $range->utcBounds();

        return DB::table('promotion_usages')
            ->join('orders', 'orders.id', '=', 'promotion_usages.order_id')
            ->join('promotions', 'promotions.id', '=', 'promotion_usages.promotion_id')
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('promotion_usages.promotion_id', 'promotions.name', 'promotions.name_bn')
            ->select('promotion_usages.promotion_id', 'promotions.name', 'promotions.name_bn')
            ->selectRaw('COUNT(DISTINCT promotion_usages.order_id) as order_count, SUM(promotion_usages.discount_amount) as discount')
            ->orderByRaw('SUM(promotion_usages.discount_amount) DESC')
            ->limit($limit)
            ->get()
            ->map(static fn (object $row): array => [
                'promotion_id' => (int) $row->promotion_id,
                'name' => (string) $row->name,
                'name_bn' => $row->name_bn === null ? null : (string) $row->name_bn,
                'orders' => (int) $row->order_count,
                'discount' => ReportMoney::of($row->discount),
            ])
            ->values()
            ->all();
    }
}
