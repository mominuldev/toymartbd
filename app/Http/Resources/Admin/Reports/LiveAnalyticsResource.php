<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Reports;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * Analytics › Live ({@see \App\Domain\Reporting\Queries\Live\LiveAnalytics}). Instants are UTC
 * ISO 8601; `today` is the Dhaka date; money is a decimal string (rule 6). `orders_today`,
 * `last_order`, the order feed items and `hourly` cover every order placed; `now`, the event
 * feed items and `viewing` cover only shoppers who allowed tracking (`tracking.consent_gated`).
 * No shopper name, phone or address ever appears (rule 20).
 *
 * @property array<string, mixed> $resource
 */
#[SchemaName('LiveAnalytics')]
final class LiveAnalyticsResource extends ReportResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        /** @var array<string, mixed>|null $last */
        $last = $r['last_order'];
        /** @var list<array<string, mixed>> $feed */
        $feed = $r['feed'];

        return [
            /** @var string UTC ISO 8601 — the server clock every window is measured from. */
            'generated_at' => self::iso($r['generated_at']),
            /** @var string Dhaka date, YYYY-MM-DD. */
            'today' => $r['today'],
            /** @var int */
            'window_minutes' => $r['window_minutes'],
            /**
             * Browser events are stored only for shoppers who allowed tracking; sessions are GA4
             * session ids, so they need GA4 on. `collecting` is false when neither GA4 nor Meta is on.
             *
             * @var array{consent_gated: bool, consent_mode: string, collecting: bool, sessions_need_ga4: bool, ga4_enabled: bool}
             */
            'tracking' => $r['tracking'],
            /** @var array{active_sessions: int, checkout_sessions: int} */
            'now' => $r['now'],
            /** @var array{count: int, value: string, same_time_yesterday: int, difference: int} */
            'orders_today' => self::plain($r['orders_today']),
            /** @var array{number: string, amount: string, district: string|null, district_bn: string|null, product_name: string|null, product_name_bn: string|null, at: string, minutes_ago: int}|null */
            'last_order' => $last === null ? null : [...self::plain($last), 'at' => self::iso($last['at'])],
            /**
             * Newest first, at most 20, from the last 24 hours.
             *
             * @var list<array{id: string, kind: 'order'|'view_item'|'add_to_cart'|'begin_checkout', at: string, minutes_ago: int, order_number: string|null, amount: string|null, district: string|null, district_bn: string|null, product_name: string|null, product_name_bn: string|null, items: int|null}>
             */
            'feed' => array_map(static fn (array $item): array => [...self::plain($item), 'at' => self::iso($item['at'])], $feed),
            /**
             * Products by distinct sessions over view_item in the window. Pages are not
             * available: the storefront never stores page_view.
             *
             * @var array{pages_available: false, pages_reason: 'no_page_views', products: list<array{key: string, name: string, name_bn: string|null, sessions: int}>}
             */
            'viewing' => $r['viewing'],
            /** @var list<array{hour: int, orders: int, value: string}> 24 Dhaka hours, 0–23. */
            'hourly' => self::plain($r['hourly']),
        ];
    }

    private static function iso(mixed $at): string
    {
        return $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : '';
    }
}
