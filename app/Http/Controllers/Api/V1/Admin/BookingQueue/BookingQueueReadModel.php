<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\BookingQueue;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Fulfilment\Enums\DeliveryZone;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\BookingLocationPreview;
use App\Domain\Fulfilment\Services\BookingPreflight;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Domain\Fulfilment\Support\CourierCredentialFields;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderItem;
use App\Support\DhakaDate;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The courier booking queue (Figma B · 07) — a read model over orders (Ordering) and
 * shipments (Fulfilment), so it lives in the HTTP layer: Fulfilment may not read Ordering's
 * models (deptrac). It never books, writes or computes money; booking goes through the
 * existing `POST /orders/{order}/shipments` and `POST /shipments/bulk` (RequestShipment /
 * RequestShipments → queued jobs, rule 4).
 *
 * Tabs, and what each counts (docs/api/booking-queue.md):
 *
 *   ready         orders in `ready_to_ship` (packed) with no open shipment — the same
 *                 "open" rule RequestShipment enforces, so a failed/cancelled shipment frees
 *                 the order again;
 *   submitted     shipments `pending` (requested, job not yet run) or `submitted` (bulk or
 *                 uncertain: no consignment id yet, rule 24d);
 *   booked_today  shipments whose `booked_at` falls on today's Dhaka date (rule 11),
 *                 whatever their status since;
 *   failed        `Shipment::needsAttention()` — the retry queue `/shipments/failed` reads;
 *   unmapped      (courier, area) pairs among READY orders where an active courier that
 *                 needs its own zone ids has none mapped for the address (rule 32) — counted
 *                 per area, not per order. These orders still appear under `ready`, blocked
 *                 for that courier.
 *
 * Per ready order and courier, `blocked_reason` mirrors what BookingPreflight and the booking
 * panel's quote would refuse: provider (`inactive`, `not_connected`,
 * `unverified_in_production`) first, then the order (`missing_address`, `not_serviced`,
 * `unmapped_location`, `ambiguous_location`, `missing_weight`). `ambiguous_location` can be
 * resolved in the single-order drawer (staff choose the zone) but never in bulk.
 */
final readonly class BookingQueueReadModel
{
    public const int PER_PAGE = 50;

    public const string HEALTH_UNSUPPORTED = 'unsupported';

    public const string HEALTH_NONE = 'none';

    public const string HEALTH_HEALTHY = 'healthy';

    public const string HEALTH_FAILING = 'failing';

    public function __construct(
        private BookingLocationPreview $locations,
        private DeliveryChargeResolver $zones,
        private DeliverySettings $settings,
    ) {}

    /** @return Builder<Order> */
    public static function readyOrders(): Builder
    {
        return Order::query()
            ->where('status', OrderStatus::ReadyToShip->value)
            ->whereNotIn('id', Shipment::query()->open()->select('order_id'));
    }

    /** @return Builder<Shipment> */
    public static function awaitingId(): Builder
    {
        return Shipment::query()->whereIn('status', [ShipmentStatus::Pending->value, ShipmentStatus::Submitted->value]);
    }

    /** @return Builder<Shipment> */
    public static function bookedToday(): Builder
    {
        [$start, $end] = DhakaDate::utcBounds(DhakaDate::today());

        return Shipment::query()->where('booked_at', '>=', $start)->where('booked_at', '<', $end);
    }

    /** @return Builder<Shipment> */
    public static function failed(): Builder
    {
        return Shipment::query()->needsAttention();
    }

    /** @return EloquentCollection<int, CourierProvider> */
    public function providers(): EloquentCollection
    {
        return CourierProvider::query()
            ->addSelect(['bookings_count' => Shipment::query()->selectRaw('count(*)')->whereColumn('provider_id', 'courier_providers.id')->whereNotNull('consignment_id')])
            ->orderBy('position')->orderBy('id')->get();
    }

    /** Provider-level block, the same order the booking panel's quote uses. */
    public static function providerBlock(CourierProvider $p): ?string
    {
        return match (true) {
            ! $p->is_active => 'inactive',
            ! CourierCredentialFields::complete($p) => 'not_connected',
            ! $p->verified && BookingPreflight::isProduction() => 'unverified_in_production',
            default => null,
        };
    }

    /** @return 'verified'|'unverified'|'not_connected' */
    public static function trust(CourierProvider $p): string
    {
        return ! CourierCredentialFields::complete($p) ? 'not_connected' : ($p->verified ? 'verified' : 'unverified');
    }

    /**
     * Header facts, courier cards and tab counts.
     *
     * @return array{production: bool, cutoff_time: string, today: string, couriers: list<array<string, mixed>>, counts: array{ready: int, submitted: int, booked_today: int, failed: int, unmapped: int}}
     */
    public function summary(): array
    {
        $providers = $this->providers();
        $bookedToday = self::bookedToday()->selectRaw('provider_id, count(*) as n')->groupBy('provider_id')->pluck('n', 'provider_id');
        $awaiting = self::awaitingId()->selectRaw('provider_id, count(*) as n')->groupBy('provider_id')->pluck('n', 'provider_id');

        $couriers = $providers->map(fn (CourierProvider $p): array => [
            'provider' => $p,
            'trust' => self::trust($p),
            'blocked_reason' => self::providerBlock($p),
            'bookings_count' => (int) $p->getAttribute('bookings_count'),
            'booked_today' => (int) ($bookedToday[$p->id] ?? 0),
            'awaiting_id' => (int) ($awaiting[$p->id] ?? 0),
            'webhook' => $this->webhook($p),
        ])->values()->all();

        return [
            'production' => BookingPreflight::isProduction(),
            'cutoff_time' => $this->settings->cutoffTime(),
            'today' => DhakaDate::today(),
            'couriers' => $couriers,
            'counts' => [
                'ready' => self::readyOrders()->count(),
                'submitted' => self::awaitingId()->count(),
                'booked_today' => self::bookedToday()->count(),
                'failed' => self::failed()->count(),
                'unmapped' => count($this->unmapped($providers)),
            ],
        ];
    }

    /**
     * Webhook health from the verified receipts only (`webhook_receipts`, rule 19): the
     * latest receipt's time and whether processing it errored. No receipt yet is `none`, not
     * "healthy" — a courier that has never called back has proved nothing.
     *
     * @return array{supported: bool, health: string, last_received_at: string|null}
     */
    private function webhook(CourierProvider $p): array
    {
        if (! $p->supports_webhook) {
            return ['supported' => false, 'health' => self::HEALTH_UNSUPPORTED, 'last_received_at' => null];
        }
        $last = CourierWebhookReceipt::query()
            ->where('provider', CourierWebhookReceipt::providerKey($p->code))
            ->orderByDesc('id')->first();
        if ($last === null) {
            return ['supported' => true, 'health' => self::HEALTH_NONE, 'last_received_at' => null];
        }

        return [
            'supported' => true,
            'health' => $last->error === null && $last->signature_valid ? self::HEALTH_HEALTHY : self::HEALTH_FAILING,
            'last_received_at' => $last->created_at?->toIso8601String(),
        ];
    }

    /**
     * One row per ready order on this page, with the per-courier block reasons.
     *
     * @param  EloquentCollection<int, Order>  $orders
     * @param  EloquentCollection<int, CourierProvider>  $providers
     * @return list<array<string, mixed>>
     */
    public function readyRows(EloquentCollection $orders, EloquentCollection $providers): array
    {
        if ($orders->isEmpty()) {
            return [];
        }
        $weights = $this->weights($orders->modelKeys());
        $districts = District::query()->whereIn('id', $orders->pluck('shipping_district_id')->filter()->unique())->get()->keyBy('id');
        $upazilas = Upazila::query()->whereIn('id', $orders->pluck('shipping_upazila_id')->filter()->unique())->get()->keyBy('id');
        $blocks = $providers->mapWithKeys(fn (CourierProvider $p): array => [$p->id => self::providerBlock($p)]);
        $previews = [];

        $rows = [];
        foreach ($orders as $order) {
            $district = $order->shipping_district_id !== null ? $districts->get($order->shipping_district_id) : null;
            $upazila = $order->shipping_upazila_id !== null ? $upazilas->get($order->shipping_upazila_id) : null;
            $zone = $district instanceof District ? $this->zones->zoneFor($district, $upazila instanceof Upazila ? $upazila : null) : null;
            $weight = $weights[$order->id] ?? ['grams' => 0, 'missing' => true, 'items' => 0];

            $couriers = [];
            foreach ($providers as $provider) {
                $key = $provider->id.':'.$order->shipping_district_id.':'.$order->shipping_upazila_id;
                $previews[$key] ??= $this->locations->for($provider, $order->shipping_district_id, $order->shipping_upazila_id)['status'];
                $location = $previews[$key];
                $couriers[] = [
                    'provider_id' => $provider->id,
                    'location_status' => $location,
                    'blocked_reason' => $blocks[$provider->id] ?? match (true) {
                        ! $district instanceof District => 'missing_address',
                        ! $zone instanceof DeliveryZone => 'not_serviced',
                        $location === 'unmapped' => 'unmapped_location',
                        $location === 'ambiguous' => 'ambiguous_location',
                        $weight['missing'] => 'missing_weight',
                        default => null,
                    },
                ];
            }

            $rows[] = [
                'order' => $order,
                'district' => $district,
                'upazila' => $upazila,
                'zone' => $zone?->value,
                'weight_grams' => $weight['grams'],
                'weight_missing' => $weight['missing'],
                'item_count' => $weight['items'],
                'couriers' => $couriers,
                'suggested_provider_id' => self::suggest($providers, $couriers),
            ];
        }

        return $rows;
    }

    /**
     * The default courier when it can take this order, else the first that can, else null
     * (every courier is blocked — the row cannot be selected).
     *
     * @param  EloquentCollection<int, CourierProvider>  $providers
     * @param  list<array{provider_id: int, location_status: string, blocked_reason: string|null}>  $couriers
     */
    private static function suggest(EloquentCollection $providers, array $couriers): ?int
    {
        $open = collect($couriers)->whereNull('blocked_reason')->pluck('provider_id');
        $default = $providers->first(fn (CourierProvider $p): bool => $p->is_default && $open->contains($p->id));

        return $default instanceof CourierProvider ? $default->id : $providers->first(fn (CourierProvider $p): bool => $open->contains($p->id))?->id;
    }

    /**
     * Parcel weight from variant weights × quantity — the same sum the booking snapshot uses
     * (EloquentShippableOrders). `missing` when any line has no variant or no weight: the
     * courier would be sent an understated parcel, which the Steadfast 1 kg step (rule 32a)
     * makes a real charge difference.
     *
     * @param  list<int>  $orderIds
     * @return array<int, array{grams: int, missing: bool, items: int}>
     */
    private function weights(array $orderIds): array
    {
        /** @var Collection<int, OrderItem> $items */
        $items = OrderItem::query()->whereIn('order_id', $orderIds)->get(['id', 'order_id', 'variant_id', 'quantity']);
        $variantWeights = ProductVariant::query()->whereIn('id', $items->pluck('variant_id')->filter()->unique())->pluck('weight_grams', 'id');

        $out = [];
        foreach ($items as $item) {
            $row = $out[$item->order_id] ?? ['grams' => 0, 'missing' => false, 'items' => 0];
            $grams = $item->variant_id !== null ? (int) ($variantWeights[$item->variant_id] ?? 0) : 0;
            $row['grams'] += $grams * $item->quantity;
            $row['items'] += $item->quantity;
            $row['missing'] = $row['missing'] || $grams <= 0;
            $out[$item->order_id] = $row;
        }

        return $out;
    }

    /**
     * Areas of ready orders that an active, zone-mapped courier cannot take because no
     * `courier_locations` row is mapped to them (rule 32 — never guessed). One row per
     * (courier, district, upazila).
     *
     * @param  EloquentCollection<int, CourierProvider>|null  $providers
     * @return list<array{provider: CourierProvider, district: District|null, upazila: Upazila|null, district_name: string|null, upazila_name: string|null, orders_count: int, order_numbers: list<string>}>
     */
    public function unmapped(?EloquentCollection $providers = null): array
    {
        $mapped = ($providers ?? $this->providers())->filter(static fn (CourierProvider $p): bool => $p->is_active && $p->requires_location_mapping)->values();
        if ($mapped->isEmpty()) {
            return [];
        }

        $groups = self::readyOrders()
            ->whereNotNull('shipping_district_id')
            ->selectRaw('shipping_district_id, shipping_upazila_id, max(shipping_district) as district_name, max(shipping_upazila) as upazila_name, count(*) as orders_count')
            ->groupBy('shipping_district_id', 'shipping_upazila_id')
            ->orderBy('shipping_district_id')->orderBy('shipping_upazila_id')
            ->toBase()->get();
        if ($groups->isEmpty()) {
            return [];
        }

        $districts = District::query()->whereIn('id', $groups->pluck('shipping_district_id')->unique())->get()->keyBy('id');
        $upazilas = Upazila::query()->whereIn('id', $groups->pluck('shipping_upazila_id')->filter()->unique())->get()->keyBy('id');

        $rows = [];
        foreach ($mapped as $provider) {
            foreach ($groups as $g) {
                $districtId = (int) $g->shipping_district_id;
                $upazilaId = $g->shipping_upazila_id !== null ? (int) $g->shipping_upazila_id : null;
                if ($this->locations->for($provider, $districtId, $upazilaId)['status'] !== 'unmapped') {
                    continue;
                }
                $numbers = self::readyOrders()
                    ->where('shipping_district_id', $districtId)
                    ->when($upazilaId === null, static fn (Builder $q) => $q->whereNull('shipping_upazila_id'), static fn (Builder $q) => $q->where('shipping_upazila_id', $upazilaId))
                    ->orderBy('id')->limit(5)->pluck('number')->all();
                /** @var District|null $district */
                $district = $districts->get($districtId);
                /** @var Upazila|null $upazila */
                $upazila = $upazilaId !== null ? $upazilas->get($upazilaId) : null;
                $rows[] = [
                    'provider' => $provider,
                    'district' => $district,
                    'upazila' => $upazila,
                    'district_name' => is_string($g->district_name) ? $g->district_name : null,
                    'upazila_name' => is_string($g->upazila_name) ? $g->upazila_name : null,
                    'orders_count' => (int) $g->orders_count,
                    'order_numbers' => array_values(array_map(strval(...), $numbers)),
                ];
            }
        }

        return $rows;
    }

    /** What the courier will collect: what is still due, never negative — as the booking snapshot. */
    public static function cod(Order $order): Money
    {
        return $order->due_total->isNegative() ? Money::zero() : $order->due_total;
    }
}
