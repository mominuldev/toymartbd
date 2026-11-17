<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Visitors;

use App\Domain\Finance\Reports\Enums\ReportGranularity;
use App\Domain\Finance\Reports\Profitability;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Reporting\Queries\Sales\PlacedOrders;
use App\Domain\Reporting\Support\DateRange;
use App\Domain\Reporting\Support\Rates;
use App\Support\DhakaDate;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Analytics › Visitors & funnel (Phase 10; Figma B · 24 premium pass 522:79774 / 522:80278).
 * Read-only; every figure and ratio is computed here (rule 1), every day is a Dhaka day
 * (rule 11). Reads `tracking_events` and `order_tracking_contexts` directly — never writes.
 *
 * What the data really supports (read before changing anything here):
 *
 *   - Only `view_item`, `add_to_cart` and `begin_checkout` reach `tracking_events` from the
 *     browser (StoreTrackingEventRequest::ALLOWED). `page_view` is never stored today, so a
 *     "visit" is a session that did at least one recorded shop action. A session that only
 *     looked at the home page or a listing is invisible. If page_view rows ever appear they
 *     are counted as visits automatically (`tracking.page_views_recorded` turns true).
 *   - `session_id` is the GA4 session id the storefront reads from the `_ga_<id>` cookie,
 *     attached only with ANALYTICS consent and only when a GA4 measurement id is configured.
 *     Every count here is therefore over analytics-consented sessions
 *     (`counted = analytics_consented_sessions`); events stored without a session (ads-only
 *     consent, no GA4) are reported apart as `events_without_session`, never guessed into a
 *     session.
 *   - The server `purchase` event carries no session id, so "placed an order" joins the
 *     order's checkout context (`order_tracking_contexts.ga_session_id`) to the sessions
 *     seen in the range. That column is blanked after `tracking.context_retention_days`
 *     (PruneOrderTrackingContexts), so for older ranges the order steps undercount —
 *     `tracking.order_link_complete` says so.
 *   - Event time is `created_at` (when we recorded it; indexed), not the browser clock.
 *   - No device, browser, city or district is stored. Nothing about them is returned.
 *
 * The funnel is open (each step counts the sessions that did that step), except that the
 * order steps only count sessions also seen visiting in the range, and delivered ⊆ placed.
 * A drop is `(previous step − this step) ÷ previous step`, null when the previous step is 0,
 * and may be negative when a later step outnumbers an earlier one. The biggest drop is taken
 * over the shopping steps (visited → placed); placed → delivered is left out because recent
 * orders are simply not delivered yet.
 */
final readonly class VisitorAnalytics
{
    public const string COUNTED = 'analytics_consented_sessions';

    /** Funnel steps in order; the key is the API's and the UI's. */
    public const array STEPS = ['visited', 'viewed_product', 'added_to_cart', 'reached_checkout', 'placed_order', 'delivered'];

    /** Steps the biggest drop is chosen among (see the class doc). */
    private const array SHOPPING_STEPS = ['visited', 'viewed_product', 'added_to_cart', 'reached_checkout', 'placed_order'];

    private const array STEP_EVENTS = [
        'viewed_product' => 'view_item',
        'added_to_cart' => 'add_to_cart',
        'reached_checkout' => 'begin_checkout',
    ];

    public const int TOP_PRODUCTS = 5;

    /** @return array<string, mixed> */
    public function report(DateRange $range): array
    {
        $previousRange = $range->previous();

        $current = $this->counts($range);
        $previous = $this->counts($previousRange);

        $summary = self::summary($current);
        $previousSummary = self::summary($previous);

        $funnel = [];
        $prior = null;
        foreach (self::STEPS as $step) {
            $sessions = $current['steps'][$step];
            $funnel[] = [
                'key' => $step,
                'sessions' => $sessions,
                'previous_sessions' => $previous['steps'][$step],
                'change' => Rates::countDelta($sessions, $previous['steps'][$step]),
                'drop_percent' => $prior === null ? null : Rates::percent($prior - $sessions, $prior),
                'of_visits_percent' => Rates::percent($sessions, $current['steps']['visited']),
            ];
            $prior = $sessions;
        }

        $retention = (int) config('tracking.context_retention_days', 90);
        $oldestLinked = DhakaDate::todayDate()->subDays($retention)->toDateString();

        return [
            'range' => $range->toArray(),
            'comparison' => $previousRange->toArray(),
            'counted' => self::COUNTED,
            'tracking' => [
                'events_recorded' => $current['events'],
                'events_without_session' => $current['events_without_session'],
                'page_views_recorded' => $current['page_views'] > 0,
                'order_link_retention_days' => $retention,
                'order_link_complete' => $previousRange->fromDate() >= $oldestLinked,
                'devices_recorded' => false,
                'places_recorded' => false,
            ],
            'summary' => $summary,
            'previous' => $previousSummary,
            'deltas' => [
                'visits' => Rates::countDelta($summary['visits'], $previousSummary['visits']),
                'placed' => Rates::countDelta($summary['placed'], $previousSummary['placed']),
                'order_rate_points' => Rates::points($summary['order_rate'], $previousSummary['order_rate']),
                'cart_to_order_points' => Rates::points($summary['cart_to_order_rate'], $previousSummary['cart_to_order_rate']),
                'delivered_rate_points' => Rates::points($summary['delivered_rate'], $previousSummary['delivered_rate']),
            ],
            'funnel' => $funnel,
            'biggest_drop' => self::biggestDrop($funnel),
            'daily' => $this->daily($range),
            'top_products' => $this->topProducts($range),
        ];
    }

    /**
     * @param  array{steps: array<string, int>, in_progress: int, orders_total: int}  $c
     * @return array{visits: int, viewed_product: int, added_to_cart: int, reached_checkout: int, placed: int, delivered: int, in_progress: int, orders_total: int, order_rate: string|null, cart_to_order_rate: string|null, delivered_rate: string|null}
     */
    private static function summary(array $c): array
    {
        $s = $c['steps'];

        return [
            'visits' => $s['visited'],
            'viewed_product' => $s['viewed_product'],
            'added_to_cart' => $s['added_to_cart'],
            'reached_checkout' => $s['reached_checkout'],
            'placed' => $s['placed_order'],
            'delivered' => $s['delivered'],
            'in_progress' => $c['in_progress'],
            'orders_total' => $c['orders_total'],
            'order_rate' => Rates::percent($s['placed_order'], $s['visited']),
            'cart_to_order_rate' => Rates::percent($s['placed_order'], $s['added_to_cart']),
            'delivered_rate' => Rates::percent($s['delivered'], $s['placed_order']),
        ];
    }

    /**
     * @param  list<array{key: string, sessions: int, drop_percent: string|null}>  $funnel
     * @return array{from: string, to: string, percent: string}|null
     */
    private static function biggestDrop(array $funnel): ?array
    {
        $best = null;
        foreach ($funnel as $i => $step) {
            if ($i === 0 || ! in_array($step['key'], self::SHOPPING_STEPS, true) || $step['drop_percent'] === null) {
                continue;
            }
            if (bccomp($step['drop_percent'], '0', 2) <= 0) {
                continue;
            }
            if ($best === null || bccomp($step['drop_percent'], $best['percent'], 2) > 0) {
                $best = ['from' => $funnel[$i - 1]['key'], 'to' => $step['key'], 'percent' => $step['drop_percent']];
            }
        }

        return $best;
    }

    /**
     * Distinct sessions per step, plus the bookkeeping the response explains itself with.
     *
     * @return array{steps: array<string, int>, events: int, events_without_session: int, page_views: int, in_progress: int, orders_total: int}
     */
    private function counts(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();

        $events = $this->events($start, $end);
        $byName = [];
        $grouped = (clone $events)
            ->whereNotNull('session_id')
            ->groupBy('event_name')
            ->select('event_name', DB::raw('COUNT(DISTINCT session_id) as sessions'))
            ->get();
        foreach ($grouped as $row) {
            $byName[(string) $row->event_name] = (int) $row->sessions;
        }

        $steps = array_fill_keys(self::STEPS, 0);
        $steps['visited'] = (int) (clone $events)->whereNotNull('session_id')->distinct()->count('session_id');
        foreach (self::STEP_EVENTS as $step => $name) {
            $steps[$step] = (int) ($byName[$name] ?? 0);
        }

        $placedOrders = $this->linkedOrders($start, $end);
        $steps['placed_order'] = (int) (clone $placedOrders)->distinct()->count('otc.ga_session_id');
        $steps['delivered'] = (int) (clone $placedOrders)
            ->whereNotNull('orders.delivered_at')
            ->whereIn('orders.status', Profitability::DELIVERED_STATUSES)
            ->distinct()->count('otc.ga_session_id');
        $inProgress = (int) (clone $placedOrders)
            ->whereNull('orders.delivered_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->distinct()->count('otc.ga_session_id');

        return [
            'steps' => $steps,
            'events' => (int) (clone $events)->count(),
            'events_without_session' => (int) (clone $events)->whereNull('session_id')->count(),
            'page_views' => (int) ($byName['page_view'] ?? 0),
            'in_progress' => $inProgress,
            'orders_total' => DB::table('orders')->where('placed_at', '>=', $start)->where('placed_at', '<', $end)->count(),
        ];
    }

    /** Browser events recorded in [start, end). Refunds and purchases are server-side and carry no session. */
    private function events(DateTimeInterface $start, DateTimeInterface $end): Builder
    {
        return DB::table('tracking_events')
            ->where('source', 'browser')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end);
    }

    /** Orders placed in [start, end) whose checkout session was seen in the same range. */
    private function linkedOrders(DateTimeInterface $start, DateTimeInterface $end): Builder
    {
        $seen = $this->events($start, $end)->whereNotNull('session_id')->select('session_id');

        return DB::table('orders')
            ->join('order_tracking_contexts as otc', 'otc.order_id', '=', 'orders.id')
            ->whereNotNull('otc.ga_session_id')
            ->where('orders.placed_at', '>=', $start)
            ->where('orders.placed_at', '<', $end)
            ->whereIn('otc.ga_session_id', $seen);
    }

    /**
     * One row per Dhaka day: sessions active that day, and sessions that placed an order that
     * day (by `orders.placed_at`).
     *
     * @return list<array{date: string, visits: int, orders: int}>
     */
    private function daily(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();
        $dayOf = PlacedOrders::bucketer($range, ReportGranularity::Day);

        $visits = [];
        $rows = $this->events($start, $end)
            ->whereNotNull('session_id')
            ->select('session_id', 'created_at')
            ->orderBy('id')
            ->cursor();
        foreach ($rows as $row) {
            if (! is_scalar($row->created_at) || ! is_scalar($row->session_id)) {
                continue;
            }
            $visits[$dayOf((string) $row->created_at)][(string) $row->session_id] = true;
        }

        $orders = [];
        $placed = $this->linkedOrders($start, $end)
            ->select('otc.ga_session_id', 'orders.placed_at')
            ->orderBy('orders.id')
            ->cursor();
        foreach ($placed as $row) {
            if (! is_scalar($row->placed_at) || ! is_scalar($row->ga_session_id)) {
                continue;
            }
            $orders[$dayOf((string) $row->placed_at)][(string) $row->ga_session_id] = true;
        }

        $days = [];
        foreach ($range->buckets(ReportGranularity::Day) as $bucket) {
            $days[] = [
                'date' => $bucket['from'],
                'visits' => count($visits[$bucket['key']] ?? []),
                'orders' => count($orders[$bucket['key']] ?? []),
            ];
        }

        return $days;
    }

    /**
     * The most viewed products: `view_item` and `add_to_cart` events per product, from the
     * event's `payload.items[].item_id` — the variant SKU (storefront lib/tracking/items.ts) —
     * mapped through product_variants to the product. An item id that matches no variant
     * (renamed or removed SKU) is left out rather than guessed.
     *
     * @return list<array{product_id: int, name: string, name_bn: string|null, views: int, add_to_carts: int, cart_rate: string|null}>
     */
    private function topProducts(DateRange $range): array
    {
        [$start, $end] = $range->utcBounds();

        $bySku = [];
        $rows = $this->events($start, $end)
            ->whereNotNull('session_id')
            ->whereIn('event_name', ['view_item', 'add_to_cart'])
            ->select('event_name', 'payload')
            ->orderBy('id')
            ->cursor();
        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
            $items = is_array($payload) && isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
            $field = $row->event_name === 'view_item' ? 'views' : 'add_to_carts';
            $skus = [];
            foreach ($items as $item) {
                if (is_array($item) && isset($item['item_id']) && is_string($item['item_id']) && $item['item_id'] !== '') {
                    $skus[$item['item_id']] = true;
                }
            }
            foreach (array_keys($skus) as $sku) {
                $bySku[$sku] ??= ['views' => 0, 'add_to_carts' => 0];
                $bySku[$sku][$field]++;
            }
        }
        if ($bySku === []) {
            return [];
        }

        $products = [];
        foreach (array_chunk(array_map('strval', array_keys($bySku)), 500) as $chunk) {
            $variants = DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('product_variants.sku', $chunk)
                ->select('product_variants.sku', 'products.id', 'products.name', 'products.name_bn')
                ->get();
            foreach ($variants as $v) {
                $id = (int) $v->id;
                $products[$id] ??= [
                    'product_id' => $id,
                    'name' => (string) $v->name,
                    'name_bn' => is_string($v->name_bn) && $v->name_bn !== '' ? $v->name_bn : null,
                    'views' => 0,
                    'add_to_carts' => 0,
                ];
                $products[$id]['views'] += $bySku[(string) $v->sku]['views'];
                $products[$id]['add_to_carts'] += $bySku[(string) $v->sku]['add_to_carts'];
            }
        }

        usort($products, static fn (array $a, array $b): int => [$b['views'], $b['add_to_carts'], $a['product_id']] <=> [$a['views'], $a['add_to_carts'], $b['product_id']]);

        return array_map(
            static fn (array $p): array => [...$p, 'cart_rate' => Rates::percent($p['add_to_carts'], $p['views'])],
            array_slice($products, 0, self::TOP_PRODUCTS),
        );
    }
}
