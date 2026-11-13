<?php

declare(strict_types=1);

use App\Domain\Fulfilment\Actions\RefreshShipmentStatus;
use App\Domain\Fulfilment\Contracts\CourierGateway;
use App\Domain\Fulfilment\Contracts\LooksUpByMerchantRef;
use App\Domain\Fulfilment\Drivers\NullCourierGateway;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierQuote;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\DTOs\CourierWebhookEvent;
use App\Domain\Fulfilment\DTOs\ShipmentRequest;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Models\CourierTrackingEvent;
use App\Domain\Fulfilment\Models\CourierWebhookReceipt;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Fulfilment\Services\CourierRegistry;
use App\Domain\Notification\Models\NotificationLog;
use App\Domain\Ordering\Enums\OrderStatus;
use Database\Seeders\DeliverySeeder;
use Database\Seeders\GeographySeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Fulfilment\Tracking\CourierTrackingKit as Kit;
use Tests\Feature\Orders\Support;

/*
 * P6 courier status ingestion: webhooks verify first (rule 19), are idempotent, and only
 * TRIGGER a re-query whose answer is applied (rule 24c); the 15-minute reconciliation poll;
 * the shipment history; the out-for-delivery message.
 */

beforeEach(function (): void {
    $this->seed([GeographySeeder::class, DeliverySeeder::class]);
    config(['risk.first_order_hold_threshold' => '100000.00']);
    CourierProvider::query()->where('code', 'steadfast')->update(['supports_webhook' => true]);
});

/** The two build invariants after any courier move (CLAUDE.md working practice). */
function assertInvariants(): void
{
    Support::assertStockInvariants();
    expect(Artisan::call('toymart:finance:verify'))->toBe(0);
}

it('rejects a bad signature with 401 before parsing or writing anything', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    $calls = count($gateway->statusCalls);

    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'e1'], 'wrong-secret')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json');
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'e2'], null)->assertUnauthorized();

    expect(CourierWebhookReceipt::query()->count())->toBe(0)
        ->and($gateway->statusCalls)->toHaveCount($calls)
        ->and($shipment->refresh()->status)->toBe(ShipmentStatus::Booked);
});

it('answers 404 for a provider without a driver, writing nothing', function (): void {
    Kit::webhook(['consignment_id' => 'X', 'status' => 'delivered'], code: 'unknowncourier')->assertNotFound();
    expect(CourierWebhookReceipt::query()->count())->toBe(0);
});

it('does not mark a parcel delivered on the webhook body — only when the re-query says so', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    $order = $shipment->order_id;

    // The courier's own API says "in transit", whatever the body claims.
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::InTransit, 'in_transit'));
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'evt-1'])
        ->assertOk()->assertJson(['received' => true, 'replayed' => false]);

    $receipt = CourierWebhookReceipt::query()->sole();
    expect($receipt->provider)->toBe('courier:steadfast')
        ->and($receipt->processed_at)->not->toBeNull()
        ->and($receipt->result)->toBe('applied')
        ->and($gateway->statusCalls)->toContain('CN-1001')
        ->and($shipment->refresh()->status)->toBe(ShipmentStatus::InTransit)
        ->and(App\Domain\Ordering\Models\Order::query()->find($order)->status)->toBe(OrderStatus::Shipped);

    // Now the provider confirms delivery; the next trigger applies it.
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::Delivered, 'delivered', collected: (string) $shipment->cod_amount->toDecimal()));
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'evt-2'])->assertOk();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and(App\Domain\Ordering\Models\Order::query()->find($order)->status)->toBe(OrderStatus::Delivered);
    assertInvariants();
});

it('treats a replayed webhook as a no-op', function (): void {
    $gateway = Kit::gateway();
    Kit::bookedShipment($gateway);
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::PickedUp, 'picked_up'));
    $body = ['consignment_id' => 'CN-1001', 'status' => 'picked_up', 'event_id' => 'evt-9'];

    Kit::webhook($body)->assertOk()->assertJson(['replayed' => false]);
    $calls = count($gateway->statusCalls);
    $events = CourierTrackingEvent::query()->count();

    Kit::webhook($body)->assertOk()->assertJson(['replayed' => true]);
    // Without a provider event id the raw bytes are the key: the same bytes again are a replay too.
    $noId = ['consignment_id' => 'CN-1001', 'status' => 'picked_up'];
    Kit::webhook($noId)->assertOk()->assertJson(['replayed' => false]);
    Kit::webhook($noId)->assertOk()->assertJson(['replayed' => true]);

    expect(CourierWebhookReceipt::query()->count())->toBe(2)
        ->and($gateway->statusCalls)->toHaveCount($calls + 1)
        ->and(CourierTrackingEvent::query()->count())->toBe($events);
});

it('acknowledges an event that names no consignment without writing it', function (): void {
    Kit::gateway();
    Kit::webhook(['event' => 'webhook_integration'])->assertOk()->assertJson(['ignored' => true]);
    expect(CourierWebhookReceipt::query()->count())->toBe(0);
});

it('records unknown consignments as processed without touching any shipment', function (): void {
    $gateway = Kit::gateway();
    Kit::webhook(['consignment_id' => 'NOPE-1', 'status' => 'delivered', 'event_id' => 'x'])->assertOk();
    expect(CourierWebhookReceipt::query()->sole()->result)->toBe('unknown_shipment')
        ->and($gateway->statusCalls)->toBe([]);
});

it('leaves the receipt open when the provider cannot be reached, and opens the circuit after repeated failures', function (): void {
    config(['couriers.circuit_breaker.failure_threshold' => 2]);
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    $gateway->willReportStatus('CN-1001', new RuntimeException('timeout'));

    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'down-1'])->assertOk();
    $receipt = CourierWebhookReceipt::query()->sole();

    expect($receipt->processed_at)->toBeNull()
        ->and($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and(app(CourierCircuitBreaker::class)->failures('steadfast'))->toBe(1);

    // A second failure reaches the threshold: the circuit opens and the next trigger calls nothing.
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'down-2'])->assertOk();
    $calls = count($gateway->statusCalls);
    Kit::webhook(['consignment_id' => 'CN-1001', 'status' => 'delivered', 'event_id' => 'down-3'])->assertOk();
    expect(app(CourierCircuitBreaker::class)->isOpen('steadfast'))->toBeTrue()
        ->and($gateway->statusCalls)->toHaveCount($calls)
        ->and(CourierWebhookReceipt::query()->whereNull('processed_at')->count())->toBe(3);
});

it('polls stale in-flight shipments and applies the courier answer', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    Shipment::query()->whereKey($shipment->id)->update(['updated_at' => now()->subHours(2)]);
    Cache::flush();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::Delivered, 'delivered', collected: $shipment->cod_amount->toDecimal()));

    $this->artisan('toymart:couriers:poll')->assertSuccessful()->expectsOutputToContain('1 shipment(s) queued');

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and(App\Domain\Ordering\Models\Order::query()->find($shipment->order_id)->status)->toBe(OrderStatus::Delivered);
    assertInvariants();
});

it('never polls terminal, fresh, recently polled or not-yet-booked shipments', function (): void {
    $gateway = Kit::gateway();
    $fresh = Kit::bookedShipment($gateway, consignment: 'CN-FRESH');
    $delivered = Kit::bookedShipment($gateway, consignment: 'CN-DONE');
    $gateway->willReportStatus('CN-DONE', Kit::result(ShipmentStatus::Delivered, 'delivered'));
    app(RefreshShipmentStatus::class)->handle($delivered->id, 'poll');
    expect($delivered->refresh()->status)->toBe(ShipmentStatus::Delivered);

    $polled = Kit::bookedShipment($gateway, consignment: 'CN-POLLED');
    Shipment::query()->whereKey([$delivered->id, $polled->id])->update(['updated_at' => now()->subHours(3)]);
    Cache::flush();
    app(RefreshShipmentStatus::class)->handle($polled->id, 'poll'); // polled just now
    Shipment::query()->whereKey($polled->id)->update(['updated_at' => now()->subHours(3)]);

    $gateway->statusCalls = [];
    $this->artisan('toymart:couriers:poll')->assertSuccessful()->expectsOutputToContain('0 shipment(s) queued');

    expect($gateway->statusCalls)->toBe([])
        ->and($fresh->refresh()->status)->toBe(ShipmentStatus::Booked);
});

it('skips a provider whose circuit is open', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);
    Shipment::query()->whereKey($shipment->id)->update(['updated_at' => now()->subHours(2)]);
    Cache::flush();
    $breaker = app(CourierCircuitBreaker::class);
    foreach (range(1, 10) as $_) {
        $breaker->recordFailure('steadfast');
    }
    $gateway->statusCalls = [];

    $this->artisan('toymart:couriers:poll')->assertSuccessful()->expectsOutputToContain('Skipped (no driver or circuit open): steadfast');
    expect($gateway->statusCalls)->toBe([]);
});

it('resolves a bulk-submitted shipment by merchant ref when the driver can look it up', function (): void {
    $null = new NullCourierGateway('steadfast', webhookSecret: Kit::SECRET);
    $byRef = new class($null) implements CourierGateway, LooksUpByMerchantRef
    {
        /** @var array<string, CourierStatusResult> */
        public array $refs = [];

        public function __construct(private readonly NullCourierGateway $inner) {}

        public function statusByMerchantRef(string $merchantOrderRef): ?CourierStatusResult
        {
            return $this->refs[$merchantOrderRef] ?? null;
        }

        public function code(): string
        {
            return $this->inner->code();
        }

        public function requiresLocationMapping(): bool
        {
            return false;
        }

        public function supportsBulk(): bool
        {
            return true;
        }

        public function quote(ShipmentRequest $r): CourierQuote
        {
            return $this->inner->quote($r);
        }

        public function book(ShipmentRequest $r): BookingResult
        {
            return $this->inner->book($r);
        }

        public function bookMany(array $rs): BulkBookingResult
        {
            return $this->inner->bookMany($rs);
        }

        public function status(string $consignmentId): CourierStatusResult
        {
            return $this->inner->status($consignmentId);
        }

        public function verifyWebhook(array $headers, string $rawBody): bool
        {
            return $this->inner->verifyWebhook($headers, $rawBody);
        }

        public function parseWebhook(string $rawBody): ?CourierWebhookEvent
        {
            return $this->inner->parseWebhook($rawBody);
        }
    };
    app(CourierRegistry::class)->register('steadfast', $byRef);

    $order = Kit::confirmedOrder();
    $shipment = Shipment::query()->create([
        'order_id' => $order->id, 'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-000777', 'status' => ShipmentStatus::Submitted, 'pickup_requested_at' => now()->subHours(2),
        'cod_amount' => $order->due_total, 'declared_value' => $order->grand_total, 'weight_grams' => 900, 'item_count' => 2,
    ]);
    Shipment::query()->whereKey($shipment->id)->update(['updated_at' => now()->subHours(2)]);

    // Not known to the courier yet: stays submitted (rule 24d).
    $this->artisan('toymart:couriers:poll')->assertSuccessful();
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Submitted);

    Cache::flush();
    Shipment::query()->whereKey($shipment->id)->update(['updated_at' => now()->subHours(2)]);
    $byRef->refs['SHP-26-000777'] = new CourierStatusResult(ShipmentStatus::Booked, 'in_review', consignmentId: '987654', trackingCode: 'ABC123');
    $this->artisan('toymart:couriers:poll')->assertSuccessful();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->consignment_id)->toBe('987654')
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipped);
    assertInvariants();
});

it('resolves a submitted shipment from a webhook only after the courier confirms that consignment', function (): void {
    $gateway = Kit::gateway();
    $order = Kit::confirmedOrder();
    $shipment = Shipment::query()->create([
        'order_id' => $order->id, 'provider_id' => CourierProvider::query()->where('code', 'steadfast')->value('id'),
        'number' => 'SHP-26-000888', 'status' => ShipmentStatus::Submitted, 'pickup_requested_at' => now(),
        'cod_amount' => $order->due_total, 'declared_value' => $order->grand_total, 'weight_grams' => 900, 'item_count' => 2,
    ]);
    $gateway->willReportStatus('55501', Kit::result(ShipmentStatus::PickedUp, 'picked_up'));

    Kit::webhook(['consignment_id' => '55501', 'merchant_order_ref' => 'SHP-26-000888', 'status' => 'delivered', 'event_id' => 'b1'])->assertOk();

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::PickedUp)
        ->and($shipment->consignment_id)->toBe('55501')
        ->and($gateway->statusCalls)->toBe(['55501'])
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipped);
    assertInvariants();
});

it('records the courier history and messages the customer once when the parcel goes out for delivery', function (): void {
    $gateway = Kit::gateway();
    $shipment = Kit::bookedShipment($gateway);

    $this->travel(1)->hours();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::InTransit, 'in_transit'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
    $this->travel(1)->hours();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::OutForDelivery, 'out_for_delivery'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'webhook');
    // A second attempt the next day sends no second message.
    $this->travel(3)->hours();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::InTransit, 'in_transit'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');
    $this->travel(1)->days();
    $gateway->willReportStatus('CN-1001', Kit::result(ShipmentStatus::OutForDelivery, 'out_for_delivery'));
    app(RefreshShipmentStatus::class)->handle($shipment->id, 'poll');

    $history = CourierTrackingEvent::query()->where('shipment_id', $shipment->id)->orderBy('id')->pluck('normalised_status')->map->value->all();
    expect($history)->toBe(['booked', 'in_transit', 'out_for_delivery', 'in_transit', 'out_for_delivery'])
        ->and(NotificationLog::query()->where('notifiable_id', $shipment->order_id)->where('template', 'order.out_for_delivery.no_rider')->count())->toBe(1);
});
