<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Live;

use App\Domain\Finance\Reports\ReportMoney;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Analytics › Live (Figma B · 24 premium pass 522:81629): what is happening in the shop right
 * now. Every window is the SERVER's clock — never the browser's — and every day is a Dhaka day
 * (rule 11). Read-only, bounded: the last {@see self::WINDOW_MINUTES} minutes of
 * `tracking_events`, today's and yesterday's orders, and the newest {@see self::FEED_SIZE}
 * feed items from the last {@see self::FEED_HOURS} hours.
 *
 * What the tracking data really supports (read off the storefront, lib/tracking):
 *  - browser events (view_item, add_to_cart, begin_checkout) are stored only when the shopper
 *    allowed analytics or ads AND GA4 or Meta is switched on; page_view is never posted, so there
 *    are no page counts — only products (view_item items);
 *  - `session_id` is the GA4 session id from the `_ga_<id>` cookie: present only with analytics
 *    consent and GA4 on. A session is counted by it; an event without one shows in the feed but
 *    is not a session;
 *  - purchase rows are server-side and carry no session id, so "ordered after starting
 *    checkout" is matched through `order_tracking_contexts.ga_session_id` (the checkout's own
 *    browser context — read for the match only, never returned).
 * Orders, by contrast, are every order placed, consent or not.
 *
 * PII-safe by construction (rule 20): an order leaves as number, amount, district and its first
 * product name — never the shopper's name, phone or address; a tracking event as its product
 * name, item count and value. Session ids never leave.
 */
final class LiveAnalytics
{
    public const int WINDOW_MINUTES = 30;

    public const int FEED_SIZE = 20;

    public const int FEED_HOURS = 24;

    public const int VIEWING_SIZE = 5;

    /** The browser funnel events the feed shows (purchase rows duplicate the orders). */
    private const array FEED_EVENTS = ['view_item', 'add_to_cart', 'begin_checkout'];

    /**
     * @param  array{consent_mode: string, enabled: array{ga4: bool, meta: bool}}  $tracking  the storefront's public tracking config
     * @return array<string, mixed>
     */
    public function report(array $tracking): array
    {
        $now = DhakaDate::instant();
        $windowStart = $now->subMinutes(self::WINDOW_MINUTES);
        $today = DhakaDate::dayOf($now);
        [$todayStart, $todayEnd] = DhakaDate::utcBounds($today);
        [$yesterdayStart] = DhakaDate::utcBounds(DhakaDate::dayOf($todayStart->subDay()));

        $orders = $this->ordersToday($todayStart, $todayEnd, $now);
        $yesterdaySoFar = DB::table('orders')
            ->where('placed_at', '>=', $yesterdayStart)
            ->where('placed_at', '<', $now->subDay())
            ->count();

        $products = new ProductNames;

        return [
            'generated_at' => $now,
            'today' => $today,
            'window_minutes' => self::WINDOW_MINUTES,
            'tracking' => [
                // Both consent modes honour a refusal, so browser figures are always consent-gated.
                'consent_gated' => true,
                'consent_mode' => $tracking['consent_mode'],
                'collecting' => $tracking['enabled']['ga4'] || $tracking['enabled']['meta'],
                'sessions_need_ga4' => true,
                'ga4_enabled' => $tracking['enabled']['ga4'],
            ],
            'now' => [
                'active_sessions' => $this->activeSessions($windowStart),
                'checkout_sessions' => $this->checkoutSessions($windowStart),
            ],
            'orders_today' => [
                'count' => $orders['count'],
                'value' => $orders['value'],
                'same_time_yesterday' => $yesterdaySoFar,
                'difference' => $orders['count'] - $yesterdaySoFar,
            ],
            'last_order' => $this->lastOrder($now),
            'feed' => $this->feed($now, $products),
            'viewing' => [
                'pages_available' => false,
                'pages_reason' => 'no_page_views',
                'products' => $this->viewing($windowStart, $products),
            ],
            'hourly' => $orders['hourly'],
        ];
    }

    /** Distinct GA4 sessions with any stored event in the window. */
    private function activeSessions(CarbonImmutable $from): int
    {
        return (int) DB::table('tracking_events')
            ->where('created_at', '>=', $from)
            ->whereNotNull('session_id')
            ->distinct()
            ->count('session_id');
    }

    /**
     * Sessions that began checkout in the window and have no order whose checkout context
     * carries the same GA4 session id at or after their latest begin_checkout.
     */
    private function checkoutSessions(CarbonImmutable $from): int
    {
        $started = DB::table('tracking_events')
            ->where('event_name', 'begin_checkout')
            ->where('created_at', '>=', $from)
            ->whereNotNull('session_id')
            ->groupBy('session_id')
            ->select('session_id')
            ->selectRaw('MAX(created_at) as last_at')
            ->pluck('last_at', 'session_id');
        if ($started->isEmpty()) {
            return 0;
        }

        $ordered = [];
        foreach (array_chunk(array_map(strval(...), $started->keys()->all()), 500) as $chunk) {
            $contexts = DB::table('order_tracking_contexts')
                ->whereIn('ga_session_id', $chunk)
                ->where('created_at', '>=', $from)
                ->get(['ga_session_id', 'created_at']);
            foreach ($contexts as $context) {
                $session = (string) $context->ga_session_id;
                if (CarbonImmutable::parse((string) $context->created_at, 'UTC')->greaterThanOrEqualTo(CarbonImmutable::parse((string) $started[$session], 'UTC'))) {
                    $ordered[$session] = true;
                }
            }
        }

        return $started->count() - count($ordered);
    }

    /**
     * Every order placed today (Dhaka), cancelled ones included — placed demand, not revenue
     * (rule 10), as on the dashboard — with its 24 Dhaka-hour buckets.
     *
     * @return array{count: int, value: Money, hourly: list<array{hour: int, orders: int, value: Money}>}
     */
    private function ordersToday(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): array
    {
        $hourly = [];
        for ($h = 0; $h < 24; $h++) {
            $hourly[$h] = ['hour' => $h, 'orders' => 0, 'value' => Money::zero()];
        }
        $count = 0;
        $value = Money::zero();

        $rows = DB::table('orders')
            ->where('placed_at', '>=', $start)
            ->where('placed_at', '<', $end)
            ->where('placed_at', '<=', $now)
            ->select(['placed_at', 'grand_total'])
            ->orderBy('id')
            ->cursor();
        foreach ($rows as $row) {
            $hour = (int) DhakaDate::local(CarbonImmutable::parse((string) $row->placed_at, 'UTC'))->format('G');
            $amount = ReportMoney::of($row->grand_total);
            $count++;
            $value = $value->plus($amount);
            $hourly[$hour]['orders']++;
            $hourly[$hour]['value'] = $hourly[$hour]['value']->plus($amount);
        }

        return ['count' => $count, 'value' => $value, 'hourly' => array_values($hourly)];
    }

    /** @return array{number: string, amount: Money, district: string|null, district_bn: string|null, product_name: string|null, product_name_bn: string|null, at: CarbonImmutable, minutes_ago: int}|null */
    private function lastOrder(CarbonImmutable $now): ?array
    {
        $row = $this->orderRows($now, 1, null)[0] ?? null;
        if ($row === null) {
            return null;
        }
        $item = DB::table('order_items')
            ->where('order_id', $row['id'])
            ->orderBy('id')
            ->first(['product_name', 'product_name_bn']);

        unset($row['id']);

        return [
            ...$row,
            'product_name' => $item === null ? null : (string) $item->product_name,
            'product_name_bn' => $item?->product_name_bn === null ? null : (string) $item->product_name_bn,
        ];
    }

    /** @return list<array{id: int, number: string, amount: Money, district: string|null, district_bn: string|null, at: CarbonImmutable, minutes_ago: int}> */
    private function orderRows(CarbonImmutable $now, int $limit, ?CarbonImmutable $since): array
    {
        return DB::table('orders')
            ->leftJoin('districts', 'districts.id', '=', 'orders.shipping_district_id')
            ->whereNotNull('orders.placed_at')
            ->when($since !== null, static fn ($q) => $q->where('orders.placed_at', '>=', $since))
            ->where('orders.placed_at', '<=', $now)
            ->orderByDesc('orders.placed_at')
            ->orderByDesc('orders.id')
            ->limit($limit)
            ->get(['orders.id', 'orders.number', 'orders.grand_total', 'orders.placed_at', 'orders.shipping_district', 'districts.name as district_name', 'districts.name_bn as district_bn'])
            ->map(static function (object $row) use ($now): array {
                $at = CarbonImmutable::parse((string) $row->placed_at, 'UTC');
                $district = $row->district_name ?? $row->shipping_district;

                return [
                    'id' => (int) $row->id,
                    'number' => (string) $row->number,
                    'amount' => ReportMoney::of($row->grand_total),
                    'district' => $district === null ? null : (string) $district,
                    'district_bn' => $row->district_bn === null ? null : (string) $row->district_bn,
                    'at' => $at,
                    'minutes_ago' => self::minutesAgo($at, $now),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The newest orders and browser funnel events, merged newest first.
     *
     * @return list<array{id: string, kind: string, at: CarbonImmutable, minutes_ago: int, order_number: string|null, amount: Money|null, district: string|null, district_bn: string|null, product_name: string|null, product_name_bn: string|null, items: int|null}>
     */
    private function feed(CarbonImmutable $now, ProductNames $products): array
    {
        $feed = [];
        foreach ($this->orderRows($now, self::FEED_SIZE, $now->subHours(self::FEED_HOURS)) as $order) {
            $feed[] = [
                'id' => 'order-'.$order['id'],
                'kind' => 'order',
                'at' => $order['at'],
                'minutes_ago' => $order['minutes_ago'],
                'order_number' => $order['number'],
                'amount' => $order['amount'],
                'district' => $order['district'],
                'district_bn' => $order['district_bn'],
                'product_name' => null,
                'product_name_bn' => null,
                'items' => null,
            ];
        }

        $events = DB::table('tracking_events')
            ->whereIn('event_name', self::FEED_EVENTS)
            ->where('created_at', '>=', $now->subHours(self::FEED_HOURS))
            ->where('created_at', '<=', $now)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::FEED_SIZE)
            ->get(['id', 'event_name', 'value', 'payload', 'created_at']);
        $parsed = [];
        foreach ($events as $event) {
            $items = self::items($event->payload);
            $parsed[] = [$event, $items];
            $products->want(array_column($items, 'item_id'));
        }
        foreach ($parsed as [$event, $items]) {
            $at = CarbonImmutable::parse((string) $event->created_at, 'UTC');
            $first = $items[0] ?? null;
            $name = $first === null ? null : $products->name($first['item_id'], $first['item_name']);
            $feed[] = [
                'id' => 'event-'.$event->id,
                'kind' => (string) $event->event_name,
                'at' => $at,
                'minutes_ago' => self::minutesAgo($at, $now),
                'order_number' => null,
                'amount' => $event->value === null ? null : ReportMoney::of($event->value),
                'district' => null,
                'district_bn' => null,
                'product_name' => $name['name'] ?? null,
                'product_name_bn' => $name['name_bn'] ?? null,
                'items' => $items === [] ? null : array_sum(array_column($items, 'quantity')),
            ];
        }

        usort($feed, static fn (array $a, array $b): int => [$b['at']->getTimestamp(), $b['id']] <=> [$a['at']->getTimestamp(), $a['id']]);

        return array_slice($feed, 0, self::FEED_SIZE);
    }

    /**
     * Products being viewed now: distinct sessions per product over view_item in the window.
     *
     * @return list<array{key: string, name: string, name_bn: string|null, sessions: int}>
     */
    private function viewing(CarbonImmutable $from, ProductNames $products): array
    {
        $rows = DB::table('tracking_events')
            ->where('event_name', 'view_item')
            ->where('created_at', '>=', $from)
            ->whereNotNull('session_id')
            ->select(['session_id', 'payload'])
            ->orderBy('id')
            ->cursor();

        /** @var array<string, array{item_id: string, item_name: string, sessions: array<string, true>}> $groups */
        $groups = [];
        foreach ($rows as $row) {
            $item = self::items($row->payload)[0] ?? null;
            if ($item === null) {
                continue;
            }
            $groups[$item['item_id']] ??= ['item_id' => $item['item_id'], 'item_name' => $item['item_name'], 'sessions' => []];
            $groups[$item['item_id']]['sessions'][(string) $row->session_id] = true;
        }
        $products->want(array_keys($groups));

        // Variants of one product fold into the product.
        $byProduct = [];
        foreach ($groups as $group) {
            $name = $products->name($group['item_id'], $group['item_name']);
            $key = $name['key'];
            $byProduct[$key] ??= ['key' => $key, 'name' => $name['name'], 'name_bn' => $name['name_bn'], 'sessions' => []];
            $byProduct[$key]['sessions'] += $group['sessions'];
        }

        $list = array_map(static fn (array $p): array => [...$p, 'sessions' => count($p['sessions'])], array_values($byProduct));
        usort($list, static fn (array $a, array $b): int => [$b['sessions'], $a['name']] <=> [$a['sessions'], $b['name']]);

        return array_slice($list, 0, self::VIEWING_SIZE);
    }

    /** @return list<array{item_id: string, item_name: string, quantity: int}> */
    private static function items(mixed $payload): array
    {
        $data = is_string($payload) ? json_decode($payload, true) : $payload;
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['item_id'] ?? null)) {
                continue;
            }
            $out[] = [
                'item_id' => $item['item_id'],
                'item_name' => is_string($item['item_name'] ?? null) ? $item['item_name'] : $item['item_id'],
                'quantity' => is_int($item['quantity'] ?? null) ? max(0, $item['quantity']) : 1,
            ];
        }

        return $out;
    }

    private static function minutesAgo(CarbonImmutable $at, CarbonImmutable $now): int
    {
        return max(0, intdiv($now->getTimestamp() - $at->getTimestamp(), 60));
    }
}
