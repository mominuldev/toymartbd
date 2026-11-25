<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Queries\Sales;

use App\Domain\Reporting\Support\DateRange;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The browsing half of the recommendation funnel (rule 25d): impressions, clicks and
 * add-to-carts per placement, counted from `recommendation_events` over the range's Dhaka
 * days (rule 11 — the range's UTC bounds start and end at Dhaka midnight).
 *
 * The buying half (units, revenue) is not counted here: it comes from
 * `order_items.recommendation_source` ({@see PlacedOrders::recommendationLines()},
 * {@see DeliveredLines}), so a `purchased` event is never a second source of truth.
 *
 * Honesty rule: before the first event was ever written (or when the range ends before it),
 * nothing was measured, so `measured` is false and the report shows "no data yet" instead
 * of zeros. Once tracking runs, a placement with no events in the range really had none.
 */
final readonly class RecommendationFunnel
{
    /** The browser events this report counts; `purchased` is the server's and is not one of them. */
    public const array EVENTS = ['impression', 'click', 'add_to_cart'];

    /**
     * @return array{measured: bool, since: string|null, placements: array<string, array{impressions: int, clicks: int, add_to_cart: int}>}
     */
    public function collect(DateRange $range): array
    {
        $first = DB::table('recommendation_events')->whereIn('event', self::EVENTS)->min('occurred_at');
        $since = is_string($first) && $first !== '' ? DhakaDate::dayOf(CarbonImmutable::parse($first, 'UTC')) : null;

        if ($since === null || $since > $range->toDate()) {
            return ['measured' => false, 'since' => $since, 'placements' => []];
        }

        [$start, $end] = $range->utcBounds();
        $rows = DB::table('recommendation_events')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->whereIn('event', self::EVENTS)
            ->groupBy('placement', 'event')
            ->select(['placement', 'event'])
            ->selectRaw('COUNT(*) as event_count')
            ->get();

        $placements = [];
        foreach ($rows as $row) {
            $placement = (string) $row->placement;
            $placements[$placement] ??= ['impressions' => 0, 'clicks' => 0, 'add_to_cart' => 0];
            $key = match ((string) $row->event) {
                'impression' => 'impressions',
                'click' => 'clicks',
                default => 'add_to_cart',
            };
            $placements[$placement][$key] += (int) $row->event_count;
        }

        return ['measured' => true, 'since' => $since, 'placements' => $placements];
    }
}
