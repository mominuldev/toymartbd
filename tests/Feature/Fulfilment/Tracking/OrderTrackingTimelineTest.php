<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Tests\Feature\Cart\CartTestKit;
use Tests\Feature\Fulfilment\Tracking\CourierTrackingKit as Kit;

/*
 * P6 — the customer's courier milestones on public tracking (number + phone digits, rule 22)
 * and the order page: courier name (both languages), consignment, tracking link, and the
 * booked → picked up → in transit → out for delivery → delivered/returned times.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    $this->storefront = CartTestKit::storefrontToken();
});

function trackOrder(string $number, string $phone, string $locale = 'en'): Illuminate\Testing\TestResponse
{
    return test()->withToken(test()->storefront)->postJson("/api/v1/storefront/orders/track?locale={$locale}", ['number' => $number, 'phone' => $phone]);
}

it('shows the courier milestones with times, courier and tracking link', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    $shipment->forceFill(['tracking_url' => 'https://steadfast.com.bd/t/15BAEB8A'])->save();
    CourierProvider::query()->whereKey($shipment->provider_id)->update(['name_bn' => 'স্টেডফাস্ট']);

    $steps = [
        [ShipmentStatus::PickedUp, 'picked_up'],
        [ShipmentStatus::InTransit, 'in_transit'],
        [ShipmentStatus::OutForDelivery, 'out_for_delivery'],
    ];
    foreach ($steps as [$status, $raw]) {
        $this->travel(2)->hours();
        $gateway->willReportStatus('CN-1001', Kit::result($status, $raw, now()->toImmutable()));
        app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
    }

    $number = $shipment->refresh()->order_id;
    $orderNumber = App\Domain\Ordering\Models\Order::query()->findOrFail($number)->number;

    foreach (['en', 'bn'] as $locale) {
        $json = trackOrder($orderNumber, '5678', $locale)->assertOk()->json();

        expect($json['stage'])->toBe('shipped')
            ->and($json['shipment']['courier'])->toBe(['code' => 'steadfast', 'name' => CourierProvider::query()->find($shipment->provider_id)->name, 'name_bn' => 'স্টেডফাস্ট'])
            ->and($json['shipment']['status'])->toBe('out_for_delivery')
            ->and($json['shipment']['consignment_id'])->toBe('CN-1001')
            ->and($json['shipment']['tracking_url'])->toBe('https://steadfast.com.bd/t/15BAEB8A')
            ->and(array_column($json['shipment']['milestones'], 'milestone'))->toBe(['booked', 'picked_up', 'in_transit', 'out_for_delivery'])
            ->and(collect($json['shipment']['milestones'])->every(fn (array $m): bool => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $m['at']) === 1))->toBeTrue();
    }

    // Delivered completes the list.
    $this->travel(2)->hours();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::Delivered, 'delivered', now()->toImmutable(), collected: $shipment->cod_amount->toDecimal()));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
    $json = trackOrder($orderNumber, '5678')->assertOk()->json();
    expect($json['stage'])->toBe('delivered')
        ->and(array_column($json['shipment']['milestones'], 'milestone'))->toBe(['booked', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered']);
});

it('still requires the order number plus phone digits — the shipment is never shown on a miss', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    $orderNumber = App\Domain\Ordering\Models\Order::query()->findOrFail($shipment->order_id)->number;

    $miss = trackOrder($orderNumber, '1111')->assertNotFound();
    expect($miss->json())->not->toHaveKey('shipment');
    $this->withToken($this->storefront)->postJson('/api/v1/storefront/orders/track', ['number' => $orderNumber])->assertUnprocessable();
    trackOrder($orderNumber, '5678')->assertOk()->assertJsonPath('shipment.consignment_id', 'CN-1001');
});

it('shows no shipment before a courier has accepted one, and a returned parcel ends at returned', function (): void {
    $gateway = Kit::gateway();
    $order = Kit::confirmedOrder();
    $pending = Shipment::query()->create([
        'order_id' => $order->id, 'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-000999', 'status' => ShipmentStatus::Failed, 'cod_amount' => '0.00', 'declared_value' => '0.00',
    ]);
    trackOrder($order->number, '5678')->assertOk()->assertJsonPath('shipment', null);

    $shipment = Kit::bookedShipment($gateway, $order->refresh(), 'CN-RET');
    $this->travel(1)->days();
    $gateway->willReportStatus('CN-RET', Kit::result(ShipmentStatus::Returned, 'returned', now()->toImmutable()));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');

    $json = trackOrder($order->number, '5678')->assertOk()->json();
    expect($pending->id)->not->toBe($shipment->id)
        ->and($json['stage'])->toBe('returned')
        ->and($json['shipment']['status'])->toBe('returned')
        ->and(array_column($json['shipment']['milestones'], 'milestone'))->toBe(['booked', 'returned']);
    Tests\Feature\Orders\Support::assertStockInvariants();
    expect(Illuminate\Support\Facades\Artisan::call('toymart:finance:verify'))->toBe(0);
});
