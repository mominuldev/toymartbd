<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Reports;

use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCostEstimator;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shipments & tracking board (Figma B · 08, docs/api/shipments-board.md). Read-only; every
 * figure on the screen is computed here (rule 1) from `shipments`, `courier_tracking_events`
 * and, for costs, `courier_settlement_items`. Nothing is invented: a figure without the data
 * behind it is null and the screen says why.
 *
 * **Board cohort** — the shipments handed to a courier on a Dhaka date in the range (rule 11):
 * `COALESCE(booked_at, pickup_requested_at)`. Pending and booking-failed shipments never
 * reached a courier; they live on the booking queue and the failed-bookings screen.
 *
 * **Tabs** onto {@see ShipmentStatus} (tabs may overlap; "all" is the cohort):
 *   submitted ........ submitted (bulk: courier has not returned a consignment id, rule 24d)
 *   in_transit ....... booked, picked_up, in_transit, out_for_delivery, on_hold
 *   delivered ........ delivered, partially_delivered
 *   returned ......... returned (no "returning" status exists — a parcel on its way back is
 *                      still in its last in-flight status until the courier reports returned)
 *   failed_attempts .. on_hold (the drivers map pickup/delivery failures there) or
 *                      delivery_attempts ≥ 2 (a re-attempt means an earlier one failed)
 *   cancelled shipments appear under "all" only.
 */
final readonly class ShipmentsBoard
{
    /** @var list<string> */
    public const array TABS = ['all', 'submitted', 'in_transit', 'delivered', 'returned', 'failed_attempts'];

    /** A bulk-submitted parcel without a consignment id after this long needs chasing. */
    public const int CHASE_AFTER_MINUTES = 360;

    /** Delivery issues look back this many Dhaka days, today included. */
    public const int ISSUE_DAYS = 30;

    /** @var list<string> */
    private const array ON_THE_ROAD = ['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'on_hold'];

    /** @var list<string> */
    private const array DELIVERED = ['delivered', 'partially_delivered'];

    public function __construct(
        private DeliveryChargeResolver $resolver,
        private CourierCostEstimator $estimator,
    ) {}

    /**
     * Restrict to the board cohort for a Dhaka date range (inclusive).
     *
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public static function cohort(Builder $query, string $from, string $to): Builder
    {
        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($to);
        $handedOver = DB::raw('COALESCE(shipments.booked_at, shipments.pickup_requested_at)');

        return $query
            ->whereNotIn('shipments.status', [ShipmentStatus::Pending->value, ShipmentStatus::Failed->value])
            ->where($handedOver, '>=', $start)
            ->where($handedOver, '<', $end);
    }

    /**
     * @param  Builder<Shipment>  $query
     * @return Builder<Shipment>
     */
    public static function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'submitted' => $query->where('shipments.status', ShipmentStatus::Submitted->value),
            'in_transit' => $query->whereIn('shipments.status', self::ON_THE_ROAD),
            'delivered' => $query->whereIn('shipments.status', self::DELIVERED),
            'returned' => $query->where('shipments.status', ShipmentStatus::Returned->value),
            'failed_attempts' => $query->where(static fn (Builder $q) => $q
                ->where('shipments.status', ShipmentStatus::OnHold->value)
                ->orWhere('shipments.delivery_attempts', '>=', 2)),
            default => $query,
        };
    }

    /**
     * One count per tab over an already-filtered query (search, courier, range — not the tab).
     *
     * @param  Builder<Shipment>  $base
     * @return array<string, int>
     */
    public static function tabCounts(Builder $base): array
    {
        $counts = [];
        foreach (self::TABS as $tab) {
            $counts[$tab] = self::tab($base->clone(), $tab)->count();
        }

        return $counts;
    }

    /**
     * The four stat tiles. In transit and Submitted are live ("right now", not range-bound);
     * success and days-to-deliver are outcome-dated within the range.
     *
     * @return array{
     *     from: string, to: string,
     *     in_transit: array{count: int, cod_on_road: string},
     *     submitted: array{count: int, oldest_at: string|null, oldest_age_minutes: int|null, chase_after_minutes: int, overdue: bool},
     *     success: array{delivered: int, returned: int, rate: string|null},
     *     days_to_deliver: array{average: string|null, timed: int, by_zone: list<array{zone: string, average: string, timed: int}>}
     * }
     */
    public function stats(string $from, string $to, ?string $providerCode = null, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $providerId = self::providerId($providerCode);
        $scoped = static fn (): Builder => $providerId === null ? Shipment::query() : Shipment::query()->where('provider_id', $providerId);

        $road = $scoped()->whereIn('status', self::ON_THE_ROAD)
            ->get(['id', 'cod_amount']);
        $cod = $road->reduce(static fn (Money $sum, Shipment $s): Money => $sum->plus($s->cod_amount), Money::zero());

        $submitted = $scoped()->where('status', ShipmentStatus::Submitted->value);
        $count = $submitted->clone()->count();
        $oldest = $submitted->clone()->whereNotNull('pickup_requested_at')->min('pickup_requested_at');
        $oldestAt = is_string($oldest) ? CarbonImmutable::parse($oldest, 'UTC') : null;
        $age = $oldestAt === null ? null : max(0, (int) floor($oldestAt->diffInMinutes($now, true)));

        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($to);
        $delivered = $scoped()->whereIn('status', self::DELIVERED)
            ->where('delivered_at', '>=', $start)->where('delivered_at', '<', $end)
            ->get(['id', 'order_id', 'booked_at', 'pickup_requested_at', 'delivered_at']);
        $returned = $scoped()->where('status', ShipmentStatus::Returned->value)
            ->where('returned_at', '>=', $start)->where('returned_at', '<', $end)->count();

        $outcomes = $delivered->count() + $returned;
        $zones = $this->zones($delivered->pluck('order_id')->all());
        $seconds = 0;
        $timed = 0;
        /** @var array<string, array{0: int, 1: int}> $byZone */
        $byZone = [];
        foreach ($delivered as $s) {
            $handed = $s->booked_at ?? $s->pickup_requested_at;
            if ($handed === null || $s->delivered_at === null) {
                continue;
            }
            $took = max(0, (int) $s->delivered_at->getTimestamp() - $handed->getTimestamp());
            $seconds += $took;
            $timed++;
            $zone = $zones[$s->order_id] ?? null;
            if ($zone !== null) {
                $byZone[$zone->value] = [($byZone[$zone->value][0] ?? 0) + $took, ($byZone[$zone->value][1] ?? 0) + 1];
            }
        }
        $zoneRows = [];
        foreach (DeliveryZone::cases() as $zone) {
            if (isset($byZone[$zone->value])) {
                [$sum, $n] = $byZone[$zone->value];
                $zoneRows[] = ['zone' => $zone->value, 'average' => self::ratio($sum, $n * 86400, 1), 'timed' => $n];
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'in_transit' => ['count' => $road->count(), 'cod_on_road' => $cod->toDecimal()],
            'submitted' => [
                'count' => $count,
                'oldest_at' => $oldestAt?->toIso8601String(),
                'oldest_age_minutes' => $age,
                'chase_after_minutes' => self::CHASE_AFTER_MINUTES,
                'overdue' => $age !== null && $age >= self::CHASE_AFTER_MINUTES,
            ],
            'success' => [
                'delivered' => $delivered->count(),
                'returned' => $returned,
                'rate' => $outcomes === 0 ? null : self::ratio($delivered->count() * 100, $outcomes, 1),
            ],
            'days_to_deliver' => [
                'average' => $timed === 0 ? null : self::ratio($seconds, $timed * 86400, 1),
                'timed' => $timed,
                'by_zone' => $zoneRows,
            ],
        ];
    }

    /**
     * Delivery issues over the last {@see ISSUE_DAYS} Dhaka days and delivery success by
     * courier × area for the same window. Bars only where at least one parcel had an outcome.
     *
     * @return array{
     *     from: string, to: string,
     *     returned: array{count: int, cost: string, districts: list<string>},
     *     failed_attempts: array{count: int, latest_provider_status: string|null},
     *     unmapped: array{count: int, areas: list<string>},
     *     by_courier: list<array{provider: array{code: string, name: string, name_bn: string|null}, area: string, delivered: int, returned: int, rate: string}>
     * }
     */
    public function issues(?string $today = null): array
    {
        $to = $today ?? DhakaDate::today();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $to, DhakaDate::zone())?->subDays(self::ISSUE_DAYS - 1)->toDateString() ?? $to;
        [$start] = DhakaDate::utcBounds($from);
        [, $end] = DhakaDate::utcBounds($to);

        $returned = Shipment::query()->where('status', ShipmentStatus::Returned->value)
            ->where('returned_at', '>=', $start)->where('returned_at', '<', $end)
            ->get(['id', 'order_id', 'total_cost']);
        $lost = $returned->reduce(static fn (Money $sum, Shipment $s): Money => $sum->plus($s->total_cost ?? Money::zero()), Money::zero());
        $districts = $returned->isEmpty() ? [] : DB::table('orders')->whereIn('id', $returned->pluck('order_id'))
            ->whereNotNull('shipping_district')
            ->select('shipping_district')->selectRaw('COUNT(*) as n')
            ->groupBy('shipping_district')->orderByDesc('n')->orderBy('shipping_district')->limit(2)
            ->pluck('shipping_district')->map(static fn (mixed $d): string => (string) $d)->values()->all();

        $onHold = CourierTrackingEvent::query()->where('normalised_status', ShipmentStatus::OnHold->value)
            ->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end);
        $onHoldIds = $onHold->clone()->distinct()->pluck('shipment_id');
        $failed = Shipment::query()
            ->where(static fn (Builder $q) => $q->whereIn('id', $onHoldIds)
                ->orWhere(static fn (Builder $w) => $w->where('delivery_attempts', '>=', 2)->where('updated_at', '>=', $start)->where('updated_at', '<', $end)));
        $failedCount = $failed->clone()->count();
        // The courier's own words for the latest hold, not the shipment's current status.
        $latestRaw = $onHold->clone()->whereNotNull('provider_status')->orderByDesc('occurred_at')->orderByDesc('id')->value('provider_status');

        $unmapped = Shipment::query()->where('status', ShipmentStatus::Failed->value)
            ->whereIn('failure_code', [Shipment::FAILURE_UNMAPPED, Shipment::FAILURE_AMBIGUOUS])
            ->where('updated_at', '>=', $start)->where('updated_at', '<', $end);
        $unmappedOrders = $unmapped->clone()->pluck('order_id');
        $areas = $unmappedOrders->isEmpty() ? [] : DB::table('orders')->whereIn('id', $unmappedOrders)
            ->orderByDesc('id')->limit(3)->get(['shipping_upazila', 'shipping_district'])
            ->map(static fn (object $o): string => implode(', ', array_filter([
                is_string($o->shipping_upazila ?? null) ? $o->shipping_upazila : null,
                is_string($o->shipping_district ?? null) ? $o->shipping_district : null,
            ])))->filter()->unique()->values()->all();

        return [
            'from' => $from,
            'to' => $to,
            'returned' => ['count' => $returned->count(), 'cost' => $lost->toDecimal(), 'districts' => $districts],
            'failed_attempts' => ['count' => $failedCount, 'latest_provider_status' => is_string($latestRaw) ? $latestRaw : null],
            'unmapped' => ['count' => $unmappedOrders->count(), 'areas' => $areas],
            'by_courier' => $this->byCourier($start, $end),
        ];
    }

    /**
     * What each shipment costs us: the courier's settled statement when a matched line exists,
     * else what the shipment recorded (booking charge + COD fee + return charge), else an
     * estimate from the provider's rate card for the order's delivery zone.
     *
     * @param  Collection<int, Shipment>  $shipments  with `provider` loaded
     * @return array<int, array{amount: string|null, basis: 'settled'|'recorded'|'estimate'|null}>
     */
    public function costs(Collection $shipments): array
    {
        $settled = $shipments->isEmpty() ? collect() : DB::table('courier_settlement_items')
            ->whereIn('shipment_id', $shipments->pluck('id'))
            ->where('is_matched', true)
            ->where('review_status', '!=', 'excluded')
            ->orderBy('id')
            ->get(['shipment_id', 'charges', 'cod_fee', 'return_charge'])
            ->keyBy('shipment_id');

        $needsZone = $shipments->filter(static fn (Shipment $s): bool => ! $settled->has($s->id) && $s->total_cost === null && $s->status !== ShipmentStatus::Cancelled);
        $zones = $this->zones($needsZone->pluck('order_id')->all());

        $out = [];
        foreach ($shipments as $s) {
            $line = $settled->get($s->id);
            if (is_object($line)) {
                /** @var object{charges: string, cod_fee: string, return_charge: string} $line */
                $out[$s->id] = ['amount' => Money::of($line->charges)->plus(Money::of($line->cod_fee))->plus(Money::of($line->return_charge))->toDecimal(), 'basis' => 'settled'];
            } elseif ($s->total_cost !== null) {
                $out[$s->id] = ['amount' => $s->total_cost->toDecimal(), 'basis' => 'recorded'];
            } elseif ($s->status === ShipmentStatus::Cancelled || ($zone = $zones[$s->order_id] ?? null) === null) {
                $out[$s->id] = ['amount' => null, 'basis' => null];
            } else {
                $estimate = $this->estimator->estimate($s->provider, $zone, $s->weight_grams, $s->cod_amount);
                $amount = $s->status === ShipmentStatus::Returned ? $estimate->costIfRefused : $estimate->totalIfDelivered;
                $out[$s->id] = ['amount' => $amount->toDecimal(), 'basis' => 'estimate'];
            }
        }

        return $out;
    }

    /**
     * The latest courier tracking event per shipment.
     *
     * @param  list<int>  $ids
     * @return array<int, CourierTrackingEvent>
     */
    public static function lastEvents(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $latest = [];
        CourierTrackingEvent::query()->whereIn('shipment_id', $ids)
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'shipment_id', 'normalised_status', 'provider_status', 'message', 'location', 'occurred_at', 'received_at', 'source'])
            ->each(static function (CourierTrackingEvent $e) use (&$latest): void {
                $latest[$e->shipment_id] = $e;
            });

        return $latest;
    }

    /**
     * The order's delivery zone (our charge tier, not the courier's zone id — rule 32), from
     * its shipping district / upazila ids; null when the order has no district id.
     *
     * @param  list<int|string>  $orderIds
     * @return array<int, DeliveryZone|null>
     */
    public function zones(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $orders = DB::table('orders')->whereIn('id', array_unique($orderIds))->get(['id', 'shipping_district_id', 'shipping_upazila_id']);
        $districts = District::query()->whereKey($orders->pluck('shipping_district_id')->filter()->unique())->get()->keyBy('id');
        $upazilas = Upazila::query()->whereKey($orders->pluck('shipping_upazila_id')->filter()->unique())->get()->keyBy('id');

        $zones = [];
        foreach ($orders as $o) {
            /** @var object{id: int|string, shipping_district_id: int|string|null, shipping_upazila_id: int|string|null} $o */
            $district = $o->shipping_district_id === null ? null : $districts->get((int) $o->shipping_district_id);
            $upazila = $o->shipping_upazila_id === null ? null : $upazilas->get((int) $o->shipping_upazila_id);
            $zones[(int) $o->id] = $district instanceof District ? $this->resolver->zoneFor($district, $upazila instanceof Upazila ? $upazila : null) : null;
        }

        return $zones;
    }

    /** @return list<array{provider: array{code: string, name: string, name_bn: string|null}, area: string, delivered: int, returned: int, rate: string}> */
    private function byCourier(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Shipment::query()
            ->where(static fn (Builder $q) => $q
                ->where(static fn (Builder $d) => $d->whereIn('status', self::DELIVERED)->where('delivered_at', '>=', $start)->where('delivered_at', '<', $end))
                ->orWhere(static fn (Builder $r) => $r->where('status', ShipmentStatus::Returned->value)->where('returned_at', '>=', $start)->where('returned_at', '<', $end)))
            ->get(['id', 'order_id', 'provider_id', 'status']);
        $zones = $this->zones($rows->pluck('order_id')->all());

        /** @var array<string, array{provider_id: int, area: string, delivered: int, returned: int}> $buckets */
        $buckets = [];
        foreach ($rows as $s) {
            $zone = $zones[$s->order_id] ?? null;
            $area = match ($zone) {
                DeliveryZone::InsideDhakaMetro, DeliveryZone::DhakaSuburb => 'dhaka',
                DeliveryZone::OutsideDhaka => 'outside',
                null => 'unknown',
            };
            $key = $s->provider_id.'|'.$area;
            $buckets[$key] ??= ['provider_id' => $s->provider_id, 'area' => $area, 'delivered' => 0, 'returned' => 0];
            $buckets[$key][$s->status === ShipmentStatus::Returned ? 'returned' : 'delivered']++;
        }

        $providers = CourierProvider::query()->whereKey(array_unique(array_column($buckets, 'provider_id')))->get()->keyBy('id');
        $order = ['dhaka' => 0, 'outside' => 1, 'unknown' => 2];
        $out = [];
        foreach ($buckets as $b) {
            /** @var CourierProvider|null $p */
            $p = $providers->get($b['provider_id']);
            $out[] = [
                'provider' => ['code' => $p->code ?? '', 'name' => $p->name ?? '', 'name_bn' => $p?->name_bn],
                'area' => $b['area'],
                'delivered' => $b['delivered'],
                'returned' => $b['returned'],
                'rate' => self::ratio($b['delivered'] * 100, $b['delivered'] + $b['returned'], 1),
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['provider']['name'], $order[$a['area']]] <=> [$b['provider']['name'], $order[$b['area']]]);

        return $out;
    }

    private static function providerId(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        return (int) (CourierProvider::query()->where('code', $code)->value('id') ?? 0);
    }

    /** $numerator ÷ $denominator as a decimal string, rounded half-up to $places (both ≥ 0, denominator > 0). */
    private static function ratio(int $numerator, int $denominator, int $places): string
    {
        $scale = 10 ** $places;
        $scaled = intdiv(2 * $numerator * $scale + $denominator, 2 * $denominator);

        return intdiv($scaled, $scale).($places > 0 ? '.'.str_pad((string) ($scaled % $scale), $places, '0', STR_PAD_LEFT) : '');
    }
}
