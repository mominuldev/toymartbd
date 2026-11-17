<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Marketing;

use App\Domain\Finance\Reports\Profitability;
use App\Domain\Finance\Reports\ReportMoney;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Marketing ROI (P10; Figma B · 24 premium pass 522:81016, Marketing ROI tab). Read-only; every
 * figure is computed here (rule 1), every day is a Dhaka day (rule 11). It reads `ad_spends`
 * through the query builder — Reporting never imports another domain's models.
 *
 * **Campaigns** are the `utm_campaign` tag on the link a shopper arrived by (compared trimmed
 * and case-insensitively). For the range:
 *
 *   spend      the typed-in ad spend, **prorated by day**: a month's amount × (days of that
 *              month inside the range ÷ days in the month), rounded half away from zero to
 *              the poisha per spend row. A whole month in range counts in full; 1–7 Nov of a
 *              ৳3,000 November counts ৳700.
 *   orders     orders placed in the range with that tag, cancelled ones left out (demand).
 *   delivered  revenue recognised on delivery (rule 10): the net product revenue of the lines
 *              of orders **delivered** in the range with that tag — the profitability report's
 *              per-line arithmetic ({@see Profitability::lineFigures()}): after discounts,
 *              returns and completed refunds netted; delivery charges excluded.
 *   return     delivered ÷ spend, 2 dp ("4.60"); null without spend.
 *   cost/order spend ÷ orders, to the poisha; null without spend or orders.
 *
 * The headline ("back from ads", return, cost per order) counts only campaigns with spend in
 * the range, so a free tag (a newsletter link) never flatters the return. Tagged campaigns
 * without spend still get a row, and are totalled apart. Orders with no tag are one
 * "No campaign tag" row with their order sources, and never carry spend.
 *
 * **Coupons and promotions**: the non-cancelled orders placed in the range that used each one
 * (`coupon_usages`, `promotion_usages`). `cost` is the discount the usage recorded;
 * `order_value` the orders' grand total at checkout (demand, not revenue); `delivered_value`
 * the grand total of those orders delivered so far ("brought"). A **new customer** is one for
 * whom that order was their first non-cancelled order ever.
 */
final readonly class MarketingAnalytics
{
    /** At most this many coupon / promotion rows. */
    public const int DEALS_LIMIT = 20;

    /** @return array<string, mixed> */
    public function report(DateRange $range): array
    {
        $previousRange = $range->previous();
        $now = $this->campaigns($range);
        $before = $this->campaigns($previousRange);
        $deals = $this->deals($range);

        $best = null;
        foreach ($now['rows'] as $row) {
            if ($row['return_ratio'] !== null && ($best === null || bccomp($row['return_ratio'], (string) $best['return_ratio'], 2) > 0)) {
                $best = $row;
            }
        }
        $topNew = null;
        foreach ($deals['rows'] as $deal) {
            if ($deal['kind'] === 'coupon' && $deal['new_customers'] > 0 && ($topNew === null || $deal['new_customers'] > $topNew['new_customers'])) {
                $topNew = $deal;
            }
        }

        return [
            'range' => $range->toArray(),
            'comparison' => $previousRange->toArray(),
            'summary' => $now['summary'],
            'previous' => $before['summary'],
            'deltas' => [
                'spend' => Rates::moneyDelta($now['summary']['spend'], $before['summary']['spend']),
                'revenue' => Rates::moneyDelta($now['summary']['revenue'], $before['summary']['revenue']),
                'orders' => Rates::countDelta($now['summary']['orders'], $before['summary']['orders']),
            ],
            'campaigns' => $now['rows'],
            'untagged' => $now['untagged'],
            'deals' => $deals['rows'],
            'deal_totals' => $deals['totals'],
            'highlights' => [
                'best_campaign' => $best === null ? null : [
                    'campaign' => $best['campaign'], 'source' => $best['source'], 'spend' => $best['spend'],
                    'delivered_revenue' => $best['delivered_revenue'], 'return_ratio' => $best['return_ratio'],
                ],
                'top_new_customer_coupon' => $topNew === null ? null : ['name' => $topNew['name'], 'new_customers' => $topNew['new_customers']],
            ],
            'empty' => $now['summary']['spend']->isZero() && $now['rows'] === [] && $now['untagged']['orders'] === 0
                && $now['untagged']['delivered_orders'] === 0 && $deals['rows'] === [],
            'rules' => ['spend' => 'prorated_by_day', 'revenue' => 'delivered_net_product_revenue'],
        ];
    }

    /**
     * The spend each campaign gets in a range, prorated by day (see the class doc).
     *
     * @return array<string, array{campaign: string, source: string|null, spend: Money}> keyed by the normalised campaign
     */
    public function spend(DateRange $range): array
    {
        $from = self::day($range->fromDate());
        $to = self::day($range->toDate());

        $rows = DB::table('ad_spends')
            ->where('month', '>=', $from->startOfMonth()->toDateString())
            // `<` the day after: a DATE may come back with a time part on SQLite.
            ->where('month', '<', $to->addDay()->toDateString())
            ->orderBy('month')
            ->orderBy('id')
            ->get(['campaign', 'source', 'month', 'amount']);

        $spend = [];
        foreach ($rows as $row) {
            $monthStart = self::day(substr((string) $row->month, 0, 10))->startOfMonth();
            $monthEnd = $monthStart->endOfMonth()->startOfDay();
            $start = $monthStart->greaterThan($from) ? $monthStart : $from;
            $end = $monthEnd->lessThan($to) ? $monthEnd : $to;
            if ($end->lessThan($start)) {
                continue;
            }
            $inRange = (int) $start->diffInDays($end) + 1;
            $daysInMonth = $monthStart->daysInMonth;
            $amount = ReportMoney::of($row->amount);
            $share = $inRange >= $daysInMonth
                ? $amount
                : Money::of(ReportMoney::round(bcdiv(bcmul($amount->toDecimal(), (string) $inRange, 8), (string) $daysInMonth, 8)));

            $key = self::key((string) $row->campaign);
            $spend[$key] ??= ['campaign' => trim((string) $row->campaign), 'source' => null, 'spend' => Money::zero()];
            $spend[$key]['spend'] = $spend[$key]['spend']->plus($share);
            if (is_string($row->source) && $row->source !== '') {
                $spend[$key]['source'] = $row->source;
            }
        }

        return $spend;
    }

    /**
     * Campaign tags seen on orders in the last 365 days, most recently used first — the ad spend
     * form's suggestions, so a typed campaign matches what the links carry.
     *
     * @return list<string>
     */
    public function campaignSuggestions(int $limit = 50): array
    {
        [$start] = DateRange::custom(self::day(DhakaDate::today())->subDays(364)->toDateString(), DhakaDate::today())->utcBounds();

        $seen = [];
        DB::table('orders')
            ->whereNotNull('utm_campaign')
            ->where('utm_campaign', '!=', '')
            ->where('placed_at', '>=', $start)
            ->groupBy('utm_campaign')
            ->select('utm_campaign')
            ->selectRaw('MAX(placed_at) as last_used')
            ->orderByDesc('last_used')
            ->limit($limit * 2)
            ->pluck('utm_campaign')
            ->each(static function (mixed $tag) use (&$seen): void {
                $text = trim((string) $tag);
                $seen[self::key($text)] ??= $text;
            });

        return array_slice(array_values($seen), 0, $limit);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     untagged: array{orders: int, delivered_orders: int, delivered_revenue: Money, sources: list<string>},
     *     summary: array<string, mixed>,
     * }
     */
    private function campaigns(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();
        $spend = $this->spend($range);

        /** @var array<string, array{campaign: string, source: string|null, orders: int, delivered_orders: array<int, true>, delivered_revenue: Money}> $tags */
        $tags = [];
        $untagged = ['orders' => 0, 'delivered_orders' => [], 'delivered_revenue' => Money::zero(), 'sources' => []];

        $placed = DB::table('orders')
            ->where('placed_at', '>=', $start)
            ->where('placed_at', '<', $end)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('utm_campaign', 'utm_source', 'source')
            ->select(['utm_campaign', 'utm_source', 'source'])
            ->selectRaw('COUNT(*) as order_count')
            ->orderBy('utm_campaign')
            ->get();
        foreach ($placed as $row) {
            $tag = is_string($row->utm_campaign) ? trim($row->utm_campaign) : '';
            if ($tag === '') {
                $untagged['orders'] += (int) $row->order_count;
                $untagged['sources'][(string) $row->source] = ($untagged['sources'][(string) $row->source] ?? 0) + (int) $row->order_count;

                continue;
            }
            $key = self::key($tag);
            $tags[$key] ??= ['campaign' => $tag, 'source' => null, 'orders' => 0, 'delivered_orders' => [], 'delivered_revenue' => Money::zero()];
            $tags[$key]['orders'] += (int) $row->order_count;
            if ($tags[$key]['source'] === null && is_string($row->utm_source) && trim($row->utm_source) !== '') {
                $tags[$key]['source'] = trim($row->utm_source);
            }
        }

        foreach ($this->deliveredLines($range)->orderBy('order_items.id')->cursor() as $line) {
            $revenue = Profitability::lineFigures($line)['net_revenue'];
            $tag = is_string($line->utm_campaign) ? trim($line->utm_campaign) : '';
            if ($tag === '') {
                $untagged['delivered_orders'][(int) $line->order_id] = true;
                $untagged['delivered_revenue'] = $untagged['delivered_revenue']->plus($revenue);

                continue;
            }
            $key = self::key($tag);
            $tags[$key] ??= ['campaign' => $tag, 'source' => null, 'orders' => 0, 'delivered_orders' => [], 'delivered_revenue' => Money::zero()];
            $tags[$key]['delivered_orders'][(int) $line->order_id] = true;
            $tags[$key]['delivered_revenue'] = $tags[$key]['delivered_revenue']->plus($revenue);
            if ($tags[$key]['source'] === null && is_string($line->utm_source) && trim($line->utm_source) !== '') {
                $tags[$key]['source'] = trim($line->utm_source);
            }
        }

        $rows = [];
        $summary = ['spend' => Money::zero(), 'revenue' => Money::zero(), 'orders' => 0, 'delivered_orders' => 0, 'campaigns' => 0, 'unpaid_revenue' => Money::zero(), 'unpaid_orders' => 0];
        foreach (array_unique([...array_keys($spend), ...array_keys($tags)]) as $key) {
            $tag = $tags[$key] ?? null;
            $paid = $spend[$key] ?? null;
            $spent = $paid['spend'] ?? Money::zero();
            $orders = $tag['orders'] ?? 0;
            $delivered = $tag === null ? 0 : count($tag['delivered_orders']);
            $revenue = $tag['delivered_revenue'] ?? Money::zero();

            $rows[] = [
                'campaign' => $paid['campaign'] ?? (string) $tag['campaign'],
                'source' => $paid['source'] ?? $tag['source'] ?? null,
                'has_spend' => $paid !== null,
                'spend' => $spent,
                'orders' => $orders,
                'delivered_orders' => $delivered,
                'delivered_revenue' => $revenue,
                'return_ratio' => self::ratio($revenue, $spent),
                'cost_per_order' => $spent->isZero() ? null : Rates::average($spent, $orders),
            ];

            if ($paid !== null) {
                $summary['campaigns']++;
                $summary['spend'] = $summary['spend']->plus($spent);
                $summary['revenue'] = $summary['revenue']->plus($revenue);
                $summary['orders'] += $orders;
                $summary['delivered_orders'] += $delivered;
            } else {
                $summary['unpaid_revenue'] = $summary['unpaid_revenue']->plus($revenue);
                $summary['unpaid_orders'] += $orders;
            }
        }

        // Most spend first, then what they brought back, then by name — stable for a reader.
        usort($rows, static function (array $a, array $b): int {
            return [$b['spend']->toPoisha(), $b['delivered_revenue']->toPoisha(), mb_strtolower($a['campaign'])]
                <=> [$a['spend']->toPoisha(), $a['delivered_revenue']->toPoisha(), mb_strtolower($b['campaign'])];
        });

        arsort($untagged['sources']);

        $summary['return_ratio'] = self::ratio($summary['revenue'], $summary['spend']);
        $summary['cost_per_order'] = $summary['spend']->isZero() ? null : Rates::average($summary['spend'], $summary['orders']);

        return [
            'rows' => $rows,
            'untagged' => [
                'orders' => $untagged['orders'],
                'delivered_orders' => count($untagged['delivered_orders']),
                'delivered_revenue' => $untagged['delivered_revenue'],
                'sources' => array_map(strval(...), array_keys($untagged['sources'])),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Coupons and promotions used on non-cancelled orders placed in the range.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, array{cost: Money, orders: int, new_customers: int}>}
     */
    private function deals(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();
        $delivered = "'".implode("','", Profitability::DELIVERED_STATUSES)."'";

        $earlier = static fn (Builder $q): Builder => $q->from('orders as earlier')
            ->whereColumn('earlier.customer_id', 'orders.customer_id')
            ->where('earlier.status', '!=', OrderStatus::Cancelled->value)
            ->where(static function (Builder $w): void {
                $w->whereColumn('earlier.placed_at', '<', 'orders.placed_at')
                    ->orWhere(static function (Builder $tie): void {
                        $tie->whereColumn('earlier.placed_at', '=', 'orders.placed_at')->whereColumn('earlier.id', '<', 'orders.id');
                    });
            })
            ->selectRaw('1');

        $usage = static function (string $table, string $key) use ($start, $end, $delivered, $earlier): Builder {
            return DB::table($table)
                ->join('orders', 'orders.id', '=', "{$table}.order_id")
                ->where('orders.placed_at', '>=', $start)
                ->where('orders.placed_at', '<', $end)
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->groupBy("{$table}.{$key}")
                ->select("{$table}.{$key} as deal_id")
                ->selectRaw('COUNT(DISTINCT orders.id) as order_count')
                ->selectRaw("SUM({$table}.discount_amount) as cost")
                ->selectRaw('SUM(orders.grand_total) as order_value')
                ->selectRaw("SUM(CASE WHEN orders.status IN ({$delivered}) THEN 1 ELSE 0 END) as delivered_count")
                ->selectRaw("SUM(CASE WHEN orders.status IN ({$delivered}) THEN orders.grand_total ELSE 0 END) as delivered_value")
                ->selectRaw('SUM(CASE WHEN NOT EXISTS ('.$earlier(DB::query())->toSql().') THEN 1 ELSE 0 END) as new_customers')
                ->addBinding($earlier(DB::query())->getBindings(), 'select');
        };

        $rows = [];
        $totals = ['coupon' => ['cost' => Money::zero(), 'orders' => 0, 'new_customers' => 0], 'promotion' => ['cost' => Money::zero(), 'orders' => 0, 'new_customers' => 0]];

        $coupons = $usage('coupon_usages', 'coupon_id')->get();
        $couponHeads = DB::table('coupons')->whereIn('id', $coupons->pluck('deal_id')->all())->get(['id', 'code', 'type'])->keyBy('id');
        foreach ($coupons as $row) {
            $head = $couponHeads->get($row->deal_id);
            $rows[] = self::deal('coupon', (int) $row->deal_id, $head === null ? '' : mb_strtoupper((string) $head->code), null, $head === null ? '' : (string) $head->type, $row);
        }

        $promotions = $usage('promotion_usages', 'promotion_id')->get();
        $promotionHeads = DB::table('promotions')->whereIn('id', $promotions->pluck('deal_id')->all())->get(['id', 'name', 'name_bn', 'type'])->keyBy('id');
        foreach ($promotions as $row) {
            $head = $promotionHeads->get($row->deal_id);
            $rows[] = self::deal('promotion', (int) $row->deal_id, $head === null ? '' : (string) $head->name, $head === null || $head->name_bn === null ? null : (string) $head->name_bn, $head === null ? '' : (string) $head->type, $row);
        }

        foreach ($rows as $row) {
            $totals[$row['kind']]['cost'] = $totals[$row['kind']]['cost']->plus($row['cost']);
            $totals[$row['kind']]['orders'] += $row['orders'];
            $totals[$row['kind']]['new_customers'] += $row['new_customers'];
        }

        usort($rows, static fn (array $a, array $b): int => [$b['delivered_value']->toPoisha(), $b['orders'], $a['name']] <=> [$a['delivered_value']->toPoisha(), $a['orders'], $b['name']]);

        return ['rows' => array_slice($rows, 0, self::DEALS_LIMIT), 'totals' => $totals];
    }

    /** @return array<string, mixed> */
    private static function deal(string $kind, int $id, string $name, ?string $nameBn, string $type, object $row): array
    {
        return [
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'name_bn' => $nameBn,
            'type' => $type,
            'orders' => (int) $row->order_count,
            'new_customers' => (int) $row->new_customers,
            'cost' => ReportMoney::of($row->cost),
            'order_value' => ReportMoney::of($row->order_value),
            'delivered_orders' => (int) $row->delivered_count,
            'delivered_value' => ReportMoney::of($row->delivered_value),
        ];
    }

    /** Order lines of orders delivered in the range, with their campaign tag — DeliveredLines' cohort. */
    private function deliveredLines(DateRange $range): Builder
    {
        [$start, $end] = $range->utcBounds();

        $refunds = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.status', 'completed')
            ->groupBy('refund_items.order_item_id')
            ->select('refund_items.order_item_id')
            ->selectRaw('SUM(refund_items.amount) as refunded');

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoinSub($refunds, 'line_refunds', static function (JoinClause $join): void {
                $join->on('line_refunds.order_item_id', '=', 'order_items.id');
            })
            ->whereIn('orders.status', Profitability::DELIVERED_STATUSES)
            ->whereNotNull('orders.delivered_at')
            ->where('orders.delivered_at', '>=', $start)
            ->where('orders.delivered_at', '<', $end)
            ->select([
                'order_items.id', 'order_items.order_id', 'order_items.quantity', 'order_items.quantity_shipped', 'order_items.quantity_returned',
                'order_items.line_total', 'order_items.cost_price_snapshot', 'orders.utm_campaign', 'orders.utm_source', 'line_refunds.refunded',
            ]);
    }

    /** revenue ÷ spend to 2 dp ("4.60"), or null without spend. */
    private static function ratio(Money $revenue, Money $spend): ?string
    {
        if ($spend->isZero() || $spend->isNegative()) {
            return null;
        }

        return ReportMoney::round(bcdiv($revenue->toDecimal(), $spend->toDecimal(), 8));
    }

    private static function key(string $campaign): string
    {
        return mb_strtolower(trim($campaign));
    }

    private static function day(string $date): CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        assert($day instanceof CarbonImmutable);

        return $day;
    }
}
