<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Http;

/*
 * Rule 33a end to end: in production, the booking job refuses to book through Steadfast
 * while `courier_providers.verified = false` — with the REAL Steadfast driver registered
 * and credentials stored, nothing reaches the network.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    CourierProvider::query()->where('code', 'steadfast')->firstOrFail()
        ->forceFill(['credentials' => ['api_key' => 'k', 'secret_key' => 's']])->save();
});

function pendingSteadfastShipment(): Shipment
{
    $order = Order::factory()->status(OrderStatus::Confirmed)->create();

    return Shipment::query()->create([
        'order_id' => $order->id,
        'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-000777',
        'status' => ShipmentStatus::Pending,
        'weight_grams' => 900,
        'item_count' => 1,
        'cod_amount' => '1060.00',
        'declared_value' => '990.00',
    ]);
}

it('refuses a production booking through unverified Steadfast and sends nothing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $shipment = pendingSteadfastShipment();

    expect(CourierProvider::query()->where('code', 'steadfast')->value('verified'))->toBeFalse();

    // handle() directly: the refusal must happen before any courier call, wherever the job runs.
    app()->call([new BookShipment($shipment->id), 'handle']);

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_UNVERIFIED)
        ->and($shipment->consignment_id)->toBeNull();
    Http::assertNothingSent();
});
