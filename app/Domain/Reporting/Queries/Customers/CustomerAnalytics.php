<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Customers;

use App\Domain\Customer\Enums\CustomerSegment;
use App\Domain\Customer\Support\CustomerPhoneMask;
use App\Domain\Customer\Support\SegmentThresholds;
use App\Domain\Finance\Reports\ReportPeriod;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Queries\EloquentCustomerOrderHistory;
use App\Domain\Reporting\Support\DateRange;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Customer analytics (roadmap Phase 8: lifetime value, cohorts, repeat rate, segmentation).
 * Read-only; never writes. Definitions — every label in the admin says which one it shows:
 *
 *  - Lifetime value (LTV): DELIVERED revenue from the ledger projection, net of discounts and
 *    refunds, excluding delivery charges ({@see CustomerLedgerValues}). Never placed value.
 *  - An "order" for frequency, cohorts and repeat counts is a non-cancelled order, dated by
 *    `orders.created_at` and bucketed by **Dhaka** calendar month (rule 11).
 *  - Repeat-purchase rate: customers with 2+ delivered orders ÷ customers with 1+ delivered
 *    orders (all time, `customers.delivered_orders_count`).
 *  - Cohort: the Dhaka month of a customer's first non-cancelled order. Month k retention =
 *    share of the cohort that placed a non-cancelled order in month (cohort + k).
 *  - Segments: {@see SegmentThresholds} (placeholder thresholds).
 *
 * Rates and percentages are decimal strings with two places, null when nothing to divide by.
 */
final readonly class CustomerAnalytics
{
    /**
     * The whole page for one range: the period block uses the range as given; cohorts span
     * the whole Dhaka months the range touches (capped at today).
     *
     * @return array<string, mixed>
     */
    public function report(DateRange $range, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $values = $this->lifetimeValues();
        $thresholds = SegmentThresholds::fromConfig();

        return [
            'range' => $range->toArray(),
            'thresholds' => [
                'vip_min_lifetime_value' => $thresholds->vipMinLifetimeValue->toDecimal(),
                'at_risk_after_days' => $thresholds->atRiskAfterDays,
                'lapsed_after_days' => $thresholds->lapsedAfterDays,
            ],
            'segments' => $this->segments($now),
            'lifetime_value' => $this->distribution($values),
            'repeat_purchase' => $this->repeatPurchase(),
            'period' => $this->period($range),
            'cohorts' => $this->cohorts($range, $values),
            'top_customers' => $this->topCustomers(),
        ];
    }

    /**
     * Lifetime value of every customer that has one (delivered revenue), keyed by id.
     *
     * @return array<int, Money>
     */
    public function lifetimeValues(): array
    {
        $out = [];
        foreach (CustomerLedgerValues::subquery()->get() as $row) {
            $out[(int) $row->customer_id] = CustomerLedgerValues::money($row->lifetime_value);
        }

        return $out;
    }

    /** @return list<array{segment: string, customers: int, lifetime_value: string}> */
    public function segments(CarbonImmutable $now): array
    {
        [$sql, $bindings] = CustomerSegmentSql::make($now)->expression();
        $rows = DB::table('customers')
            ->leftJoinSub(CustomerLedgerValues::subquery(), 'ltv', 'ltv.customer_id', '=', 'customers.id')
            ->whereNull('customers.deleted_at')
            ->selectRaw("{$sql} as segment", $bindings)
            ->selectRaw('COALESCE(ltv.lifetime_value, 0) as value')
            ->get();

        $acc = [];
        foreach (CustomerSegment::precedence() as $segment) {
            $acc[$segment->value] = ['customers' => 0, 'value' => Money::zero()];
        }
        foreach ($rows as $row) {
            $key = (string) $row->segment;
            $acc[$key]['customers']++;
            $acc[$key]['value'] = $acc[$key]['value']->plus(CustomerLedgerValues::money($row->value));
        }

        return array_map(
            static fn (string $key, array $a): array => ['segment' => $key, 'customers' => $a['customers'], 'lifetime_value' => $a['value']->toDecimal()],
            array_keys($acc),
            $acc,
        );
    }

    /**
     * @param  array<int, Money>  $values
     * @return array{customers_with_value: int, total: string, average: string|null, median: string|null, buckets: list<array{from: string, to: string|null, customers: int, lifetime_value: string}>}
     */
    public function distribution(array $values): array
    {
        $positive = array_values(array_filter($values, static fn (Money $m): bool => $m->greaterThan(Money::zero())));
        usort($positive, static fn (Money $a, Money $b): int => $a->compareTo($b));
        $total = array_reduce($positive, static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero());
        $n = count($positive);

        $median = null;
        if ($n > 0) {
            $median = $n % 2 === 1
                ? $positive[intdiv($n, 2)]
                : Money::of(bcdiv($positive[$n / 2 - 1]->plus($positive[$n / 2])->toDecimal(), '2', 6));
        }

        /** @var list<string> $bounds */
        $bounds = array_values(array_map(strval(...), (array) config('customers.analytics.ltv_buckets', [])));
        $buckets = [];
        $lower = Money::zero();
        foreach ([...$bounds, null] as $bound) {
            $upper = $bound === null ? null : Money::of($bound);
            $members = array_filter($positive, static fn (Money $m): bool => $m->compareTo($lower) >= 0 && ($upper === null || $m->lessThan($upper)));
            $buckets[] = [
                'from' => $lower->toDecimal(),
                'to' => $upper?->toDecimal(),
                'customers' => count($members),
                'lifetime_value' => array_reduce($members, static fn (Money $c, Money $m): Money => $c->plus($m), Money::zero())->toDecimal(),
            ];
            if ($upper !== null) {
                $lower = $upper;
            }
        }

        return [
            'customers_with_value' => $n,
            'total' => $total->toDecimal(),
            'average' => $n === 0 ? null : Money::of(bcdiv($total->toDecimal(), (string) $n, 6))->toDecimal(),
            'median' => $median?->toDecimal(),
            'buckets' => $buckets,
        ];
    }

    /** @return array{customers_with_delivered: int, repeat_customers: int, rate: string|null} */
    public function repeatPurchase(): array
    {
        $row = DB::table('customers')
            ->whereNull('deleted_at')
            ->selectRaw('SUM(CASE WHEN delivered_orders_count >= 1 THEN 1 ELSE 0 END) as buyers')
            ->selectRaw('SUM(CASE WHEN delivered_orders_count >= 2 THEN 1 ELSE 0 END) as repeaters')
            ->first();
        $buyers = (int) ($row->buyers ?? 0);
        $repeaters = (int) ($row->repeaters ?? 0);

        return ['customers_with_delivered' => $buyers, 'repeat_customers' => $repeaters, 'rate' => self::percent($repeaters, $buyers)];
    }

    /**
     * Who ordered in the range: new (first non-cancelled order inside it) vs returning.
     *
     * @return array{customers: int, new_customers: int, returning_customers: int, returning_share: string|null}
     */
    public function period(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();
        $firsts = self::firstOrders();
        $rows = DB::table('orders')
            ->joinSub($firsts, 'f', 'f.customer_id', '=', 'orders.customer_id')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->where('orders.created_at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('orders.created_at', '<', $end->format('Y-m-d H:i:s'))
            ->groupBy('orders.customer_id', 'f.first_at')
            ->select('orders.customer_id', 'f.first_at')
            ->get();

        $new = 0;
        foreach ($rows as $row) {
            if ((string) $row->first_at >= $start->format('Y-m-d H:i:s')) {
                $new++;
            }
        }
        $all = $rows->count();

        return ['customers' => $all, 'new_customers' => $new, 'returning_customers' => $all - $new, 'returning_share' => self::percent($all - $new, $all)];
    }

    /**
     * Monthly acquisition cohorts with retention by month since acquisition.
     *
     * @param  array<int, Money>  $values  lifetime value by customer
     * @return list<array{month: string, customers: int, lifetime_value: string, average_lifetime_value: string|null, retention: list<array{offset: int, month: string, customers: int, rate: string|null}>}>
     */
    public function cohorts(DateRange $range, array $values): array
    {
        $period = self::monthSpan($range);
        [$start, $end] = $period->utcBounds();
        $currentMonth = substr(DhakaDate::today(), 0, 7);

        /** @var array<string, array<int, true>> $members cohort month → customer ids */
        $members = [];
        /** @var array<int, string> $cohortOf */
        $cohortOf = [];
        foreach (self::firstOrders()
            ->havingRaw('MIN(orders.created_at) >= ?', [$start->format('Y-m-d H:i:s')])
            ->havingRaw('MIN(orders.created_at) < ?', [$end->format('Y-m-d H:i:s')])
            ->get() as $row) {
            $month = self::dhakaMonth((string) $row->first_at);
            $members[$month][(int) $row->customer_id] = true;
            $cohortOf[(int) $row->customer_id] = $month;
        }

        /** @var array<string, array<int, array<int, true>>> $active cohort → offset → customer ids */
        $active = [];
        if ($cohortOf !== []) {
            foreach (array_chunk(array_keys($cohortOf), 1000) as $ids) {
                DB::table('orders')
                    ->whereIn('customer_id', $ids)
                    ->where('status', '!=', OrderStatus::Cancelled->value)
                    ->select('customer_id', 'created_at')
                    ->orderBy('id')
                    ->get()
                    ->each(static function (object $order) use ($cohortOf, &$active): void {
                        $customer = (int) $order->customer_id;
                        $cohort = $cohortOf[$customer];
                        $offset = self::monthsBetween($cohort, self::dhakaMonth((string) $order->created_at));
                        if ($offset >= 0) {
                            $active[$cohort][$offset][$customer] = true;
                        }
                    });
            }
        }

        $out = [];
        foreach (self::months($period->fromDate(), $period->toDate()) as $month) {
            $ids = array_keys($members[$month] ?? []);
            $size = count($ids);
            $value = array_reduce($ids, static fn (Money $c, int $id): Money => $c->plus($values[$id] ?? Money::zero()), Money::zero());
            $retention = [];
            $last = self::monthsBetween($month, $currentMonth);
            for ($k = 0; $k <= $last; $k++) {
                $count = count($active[$month][$k] ?? []);
                $retention[] = ['offset' => $k, 'month' => self::addMonths($month, $k), 'customers' => $count, 'rate' => self::percent($count, $size)];
            }
            $out[] = [
                'month' => $month,
                'customers' => $size,
                'lifetime_value' => $value->toDecimal(),
                'average_lifetime_value' => $size === 0 ? null : Money::of(bcdiv($value->toDecimal(), (string) $size, 6))->toDecimal(),
                'retention' => $retention,
            ];
        }

        return $out;
    }

    /**
     * The highest lifetime values. Phone is always masked here — an analytics table is a list (rule 20).
     *
     * @return list<array{id: int, name: string, phone_masked: string, orders_count: int, delivered_orders_count: int, lifetime_value: string, last_order_at: string|null}>
     */
    public function topCustomers(): array
    {
        $limit = config('customers.analytics.top_customers', 10);

        return DB::table('customers')
            ->joinSub(CustomerLedgerValues::subquery(), 'ltv', 'ltv.customer_id', '=', 'customers.id')
            ->whereNull('customers.deleted_at')
            ->where('ltv.lifetime_value', '>', 0)
            ->orderByDesc('ltv.lifetime_value')
            ->orderBy('customers.id')
            ->limit(is_int($limit) ? $limit : 10)
            ->get(['customers.id', 'customers.name', 'customers.phone', 'customers.orders_count', 'customers.delivered_orders_count', 'customers.last_order_at', 'ltv.lifetime_value'])
            ->map(static fn (object $r): array => [
                'id' => (int) $r->id,
                'name' => (string) $r->name,
                'phone_masked' => CustomerPhoneMask::mask((string) $r->phone),
                'orders_count' => (int) $r->orders_count,
                'delivered_orders_count' => (int) $r->delivered_orders_count,
                'lifetime_value' => CustomerLedgerValues::money($r->lifetime_value)->toDecimal(),
                'last_order_at' => $r->last_order_at === null ? null : CarbonImmutable::parse((string) $r->last_order_at, 'UTC')->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Statuses that count as "reached the customer" — Ordering's single definition.
     *
     * @return list<string>
     */
    public static function deliveredStatuses(): array
    {
        return array_map(static fn (OrderStatus $s): string => $s->value, EloquentCustomerOrderHistory::DELIVERED);
    }

    /** `customer_id`, `first_at` (UTC) of each customer's first non-cancelled order. */
    private static function firstOrders(): Builder
    {
        return DB::table('orders')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('MIN(orders.created_at) as first_at');
    }

    /** The whole Dhaka months the range touches, ending no later than today. */
    private static function monthSpan(DateRange $range): ReportPeriod
    {
        $from = substr($range->fromDate(), 0, 7).'-01';
        $lastMonth = CarbonImmutable::createFromFormat('!Y-m-d', substr($range->toDate(), 0, 7).'-01', 'UTC');
        assert($lastMonth instanceof CarbonImmutable);
        $to = min($lastMonth->endOfMonth()->toDateString(), DhakaDate::today());
        $max = config('customers.analytics.max_cohort_months', 24);
        $max = is_int($max) ? $max : 24;
        if (self::monthsBetween(substr($from, 0, 7), substr($to, 0, 7)) >= $max) {
            $from = self::addMonths(substr($to, 0, 7), -($max - 1)).'-01';
        }

        return ReportPeriod::of($from, max($from, $to));
    }

    private static function dhakaMonth(string $utc): string
    {
        return DhakaDate::local(CarbonImmutable::parse($utc, 'UTC'))->format('Y-m');
    }

    private static function monthsBetween(string $from, string $to): int
    {
        [$fy, $fm] = array_map(intval(...), explode('-', $from));
        [$ty, $tm] = array_map(intval(...), explode('-', $to));

        return ($ty - $fy) * 12 + ($tm - $fm);
    }

    private static function addMonths(string $month, int $k): string
    {
        [$y, $m] = array_map(intval(...), explode('-', $month));
        $index = $y * 12 + ($m - 1) + $k;

        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    /** @return list<string> Y-m for every month from..to */
    private static function months(string $from, string $to): array
    {
        $out = [];
        $month = substr($from, 0, 7);
        $last = substr($to, 0, 7);
        while ($month <= $last) {
            $out[] = $month;
            $month = self::addMonths($month, 1);
        }

        return $out;
    }

    private static function percent(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : self::fixed(bcdiv(bcmul((string) $part, '100', 6), (string) $whole, 6));
    }

    /** Half-up to two places. */
    private static function fixed(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
