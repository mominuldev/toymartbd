<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Visitors & funnel for a Dhaka-date range (VisitorAnalytics). Counts are distinct
 * analytics-consented sessions (`counted`); percentages are 2-dp strings, null when there is
 * nothing to divide by. No session id, phone, IP, user agent, device or place is ever returned
 * (rule 20) — the store records none of the last two at all (`tracking.devices_recorded`).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('VisitorAnalytics')]
final class VisitorAnalyticsResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var array{preset: string, from: string, to: string, days: int} */
            'range' => $r['range'],
            /** @var array{preset: string, from: string, to: string, days: int} */
            'comparison' => $r['comparison'],
            /**
             * Who is counted: sessions whose shopper allowed analytics tracking (the GA4 session id
             * is only attached with that consent).
             *
             * @var 'analytics_consented_sessions'
             */
            'counted' => $r['counted'],
            /**
             * `page_views_recorded` false means a visit is a session with at least one shop action
             * (product view, add to cart, checkout). `order_link_complete` false means the range
             * reaches past the checkout-context retention, so the order steps undercount.
             *
             * @var array{events_recorded: int, events_without_session: int, page_views_recorded: bool, order_link_retention_days: int, order_link_complete: bool, devices_recorded: false, places_recorded: false}
             */
            'tracking' => $r['tracking'],
            /** @var array{visits: int, viewed_product: int, added_to_cart: int, reached_checkout: int, placed: int, delivered: int, in_progress: int, orders_total: int, order_rate: string|null, cart_to_order_rate: string|null, delivered_rate: string|null} */
            'summary' => $r['summary'],
            /** @var array{visits: int, viewed_product: int, added_to_cart: int, reached_checkout: int, placed: int, delivered: int, in_progress: int, orders_total: int, order_rate: string|null, cart_to_order_rate: string|null, delivered_rate: string|null} */
            'previous' => $r['previous'],
            /** @var array{visits: string|null, placed: string|null, order_rate_points: string|null, cart_to_order_points: string|null, delivered_rate_points: string|null} */
            'deltas' => $r['deltas'],
            /** @var list<array{key: 'visited'|'viewed_product'|'added_to_cart'|'reached_checkout'|'placed_order'|'delivered', sessions: int, previous_sessions: int, change: string|null, drop_percent: string|null, of_visits_percent: string|null}> */
            'funnel' => $r['funnel'],
            /**
             * The largest drop between two shopping steps (visited → placed order), or null.
             *
             * @var array{from: string, to: string, percent: string}|null
             */
            /** @var array{from: 'visited'|'viewed_product'|'added_to_cart'|'reached_checkout'|'placed_order', to: 'viewed_product'|'added_to_cart'|'reached_checkout'|'placed_order', percent: string}|null The shopping step with the largest relative drop (delivery excluded). */
            'biggest_drop' => $r['biggest_drop'],
            /** @var list<array{date: string, visits: int, orders: int}> */
            'daily' => $r['daily'],
            /** @var list<array{product_id: int, name: string, name_bn: string|null, views: int, add_to_carts: int, cart_rate: string|null}> */
            'top_products' => self::plain($r['top_products']),
        ];
    }
}
