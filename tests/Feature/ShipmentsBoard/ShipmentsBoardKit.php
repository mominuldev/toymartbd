<?php

declare(strict_types=1);

namespace Tests\Feature\ShipmentsBoard;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Models\Order;
use Illuminate\Support\Carbon;
use Tests\Feature\Ordering\Support\OrderFixtures;

/** Fixtures for the shipments board tests: orders, shipments in a chosen state, tracking events. */
final class ShipmentsBoardKit
{
    private static int $seq = 0;

    /** @param  array<string, mixed>  $orderOverrides */
    public static function order(ProductVariant $variant, array $orderOverrides = [], int $qty = 1): Order
    {
        return OrderFixtures::place([$variant->id => $qty], overrides: $orderOverrides);
    }

    /** @param  array<string, mixed>  $overrides */
    public static function shipment(Order $order, ShipmentStatus $status, array $overrides = [], string $provider = 'steadfast'): Shipment
    {
        self::$seq++;

        return Shipment::query()->create([
            'order_id' => $order->id,
            'provider_id' => CourierProvider::query()->where('code', $provider)->value('id'),
            'number' => 'SHP-26-'.str_pad((string) self::$seq, 5, '0', STR_PAD_LEFT).random_int(0, 9),
            'status' => $status,
            'consignment_id' => $status === ShipmentStatus::Submitted ? null : 'DT'.self::$seq.random_int(1000, 9999),
            'weight_grams' => 900,
            'item_count' => 1,
            'cod_amount' => '1000.00',
            'declared_value' => '1000.00',
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>|null  $payload */
    public static function event(Shipment $s, ShipmentStatus $status, string $at, string $source = 'webhook', ?string $raw = null, ?array $payload = null): void
    {
        CourierTrackingEvent::query()->create([
            'shipment_id' => $s->id,
            'provider_id' => $s->provider_id,
            'normalised_status' => $status,
            'provider_status' => $raw,
            'occurred_at' => Carbon::parse($at, 'UTC'),
            'received_at' => Carbon::parse($at, 'UTC')->addMinute(),
            'source' => $source,
            'payload' => $payload,
        ]);
    }
}
