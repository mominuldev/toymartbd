<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Fulfilment\Actions\ApplyCourierStatus;
use App\Domain\Fulfilment\Actions\CancelShipment;
use App\Domain\Fulfilment\Actions\RequestShipment;
use App\Domain\Fulfilment\Actions\RequestShipments;
use App\Domain\Fulfilment\Actions\RetryShipment;
use App\Domain\Fulfilment\DTOs\BookingResult;
use App\Domain\Fulfilment\DTOs\BulkBookingResult;
use App\Domain\Fulfilment\DTOs\CourierStatusResult;
use App\Domain\Fulfilment\Enums\ShipmentStatus;
use App\Domain\Fulfilment\Exceptions\InvalidShipmentTransition;
use App\Domain\Fulfilment\Exceptions\ShipmentNotRequestable;
use App\Domain\Fulfilment\Jobs\BookShipment;
use App\Domain\Fulfilment\Models\CourierLocation;
use App\Domain\Fulfilment\Models\Shipment;
use App\Domain\Fulfilment\Services\CourierCircuitBreaker;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Support\CourierFixtures;

/*
 * Courier booking end to end against the NullCourierGateway fixture driver (rule 33a):
 * request → book → courier status → delivered → revenue in the ledger; failure → retry;
 * unverified provider blocked in production; unmapped area blocked; bulk → submitted;
 * circuit breaker; a job run twice books once; both invariants after every path.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->courier = CourierFixtures::install('steadfast');
    $this->a = OrderFixtures::stockedVariant(10, '600.00', '350.00');
    $this->b = OrderFixtures::stockedVariant(5, '900.00', '500.00');
});

function cbConfirmed(array $lines, array $overrides = []): Order
{
    $order = OrderFixtures::place($lines, overrides: $overrides);

    return app(TransitionOrder::class)->handle($order, OrderStatus::Confirmed);
}

function cbLevel(int $variantId): StockLevel
{
    return StockLevel::query()->where('variant_id', $variantId)->where('location_id', StockLocation::defaultId())->sole();
}

function cbInvariants(): void
{
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0, 'reserved_checked' => true]);
}

function cbLedger(int $orderId): array
{
    $shipmentIds = Shipment::query()->where('order_id', $orderId)->pluck('id');

    return FinancialTransaction::query()
        ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('source_type', ['order', 'order_reversal'])->where('source_id', $orderId))
            ->orWhere(fn ($w) => $w->where('source_type', 'shipment')->whereIn('source_id', $shipmentIds)))
        ->orderBy('id')->get()
        ->map(fn (FinancialTransaction $t): string => "{$t->source_type}:{$t->type->value}:{$t->direction->value}:{$t->amount->toDecimal()}:".($t->is_cash_movement ? 'cash' : 'nocash').':'.($t->is_recognised_revenue ? 'rec' : 'norec'))
        ->all();
}

it('books end to end: request → booked → order shipped → delivered → revenue and fees in the ledger', function (): void {
    $order = cbConfirmed([$this->b->id => 1, $this->a->id => 2]);

    $shipment = app(RequestShipment::class)->handle($order->id, userId: null);
    $shipment->refresh();

    // The job ran (sync queue): booked with the fixture's ids, order shipped, stock consumed.
    expect($shipment->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->number)->toMatch('/^SHP-\d{2}-\d{6}$/')
        ->and($shipment->consignment_id)->toBe('NULL-'.$shipment->id)
        ->and($shipment->tracking_code)->not->toBeNull()
        ->and($shipment->charge?->toDecimal())->toBe('70.00')
        ->and($shipment->isDispatched())->toBeTrue()
        ->and($shipment->booking_attempts)->toBe(1)
        ->and($shipment->cod_amount->toDecimal())->toBe('2170.00')
        ->and($shipment->booking_payload)->not->toHaveKey('phone');
    expect($this->courier->booked)->toHaveCount(1)
        ->and($this->courier->booked[0]->merchantOrderRef)->toBe($shipment->number)
        ->and($this->courier->booked[0]->landmark)->toBe('জামে মসজিদের পাশে');

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Shipped)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Fulfilled)
        ->and(cbLevel($this->a->id)->on_hand)->toBe(8)
        ->and(cbLevel($this->a->id)->reserved)->toBe(0)
        ->and(StockMovement::query()->where('type', StockMovementType::SaleOut->value)->where('reference_id', $order->id)->count())->toBe(2);
    expect(cbLedger($order->id))->toBe(['shipment:courier_charge:out:70.00:cash:rec']);
    cbInvariants();

    // The courier says delivered (agent 5's webhook/poll path calls this).
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::Delivered, 'delivered', codCollected: Money::of('2170.00')), 'poll');

    $shipment->refresh();
    $order->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->delivered_at)->not->toBeNull()
        ->and($shipment->cod_fee?->toDecimal())->toBe('21.70')
        ->and($order->status)->toBe(OrderStatus::Delivered)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Paid);
    expect(cbLedger($order->id))->toEqualCanonicalizing([
        'shipment:courier_charge:out:70.00:cash:rec',
        'order:sale_revenue:in:2100.00:nocash:rec',
        'order:cogs:out:1200.00:nocash:rec',
        'order:shipping_revenue:in:70.00:nocash:rec',
        'shipment:courier_cod_fee:out:21.70:cash:rec',
    ]);
    // Revenue, not cash: nothing recorded as cod_received (rule 10).
    expect(FinancialTransaction::query()->where('type', LedgerEntryType::CodReceived->value)->count())->toBe(0);
    cbInvariants();
});

it('fails a refused booking into the retry queue, leaves the order alone, and retries the same shipment', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $this->courier->willBook(BookingResult::refused('Invalid recipient phone', 'rejected'));

    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();

    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_REFUSED)
        ->and($shipment->error_message)->toBe('Invalid recipient phone')
        ->and(Shipment::query()->needsAttention()->pluck('id')->all())->toBe([$shipment->id])
        ->and($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(cbLevel($this->a->id)->reserved)->toBe(1)
        ->and(cbLedger($order->id))->toBe([]);

    $retried = app(RetryShipment::class)->handle($shipment);

    expect($retried->id)->toBe($shipment->id)
        ->and($retried->status)->toBe(ShipmentStatus::Booked)
        ->and($retried->failure_code)->toBeNull()
        ->and($retried->booking_attempts)->toBe(2)
        ->and($this->courier->booked)->toHaveCount(2)
        ->and($this->courier->booked[1]->merchantOrderRef)->toBe($shipment->number)
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipped)
        ->and(Shipment::query()->needsAttention()->count())->toBe(0);
    cbInvariants();
});

it('allows one open shipment per order, and a new one only after the previous failed or was cancelled', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $this->courier->willBook(BookingResult::refused('Out of coverage'));
    $first = app(RequestShipment::class)->handle($order->id)->refresh();
    expect($first->status)->toBe(ShipmentStatus::Failed);

    $second = app(RequestShipment::class)->handle($order->id)->refresh();
    expect($second->status)->toBe(ShipmentStatus::Booked)
        ->and($second->number)->not->toBe($first->number);

    expect(fn () => app(RequestShipment::class)->handle($order->id))->toThrow(ShipmentNotRequestable::class);
});

it('refuses a request for an order that is not confirmed, processing or ready to ship', function (): void {
    $pending = OrderFixtures::place([$this->a->id => 1]);

    expect(fn () => app(RequestShipment::class)->handle($pending->id))->toThrow(ShipmentNotRequestable::class)
        ->and(Shipment::query()->count())->toBe(0);
});

it('blocks production booking through an unverified provider (rule 33a), with a clear reason', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $order = cbConfirmed([$this->a->id => 1]);

    try {
        $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_UNVERIFIED)
        ->and($shipment->error_message)->toContain('not verified')
        ->and($this->courier->booked)->toBe([])
        ->and($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    cbInvariants();
});

it('books through a verified provider in production', function (): void {
    CourierFixtures::provider('steadfast', ['verified' => true]);
    app()->detectEnvironment(fn (): string => 'production');
    $order = cbConfirmed([$this->a->id => 1]);

    try {
        $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    } finally {
        app()->detectEnvironment(fn (): string => 'testing');
    }

    expect($shipment->status)->toBe(ShipmentStatus::Booked);
});

it('blocks booking into an unmapped area for a provider that needs zone ids (rule 32), and never guesses', function (): void {
    $pathao = CourierFixtures::install('pathao', requiresLocationMapping: true);
    $this->seed(Database\Seeders\GeographySeeder::class);
    [$districtId, $upazilaId] = [47, 365]; // Dhaka district, Savar
    $order = cbConfirmed([$this->a->id => 1], ['shipping_district_id' => $districtId, 'shipping_upazila_id' => $upazilaId]);

    // An unmapped synced row for the same upazila must not count.
    $provider = App\Domain\Fulfilment\Models\CourierProvider::query()->where('code', 'pathao')->sole();
    $row = CourierLocation::query()->create([
        'provider_id' => $provider->id, 'external_key' => '1:10:-', 'external_city_id' => '1', 'external_city_name' => 'Dhaka',
        'external_zone_id' => '10', 'external_zone_name' => 'Dhanmondi', 'district_id' => $districtId, 'upazila_id' => $upazilaId,
        'is_mapped' => false, 'is_active' => true,
    ]);

    $shipment = app(RequestShipment::class)->handle($order->id, 'pathao')->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_UNMAPPED)
        ->and($pathao->booked)->toBe([]);

    // Once a human maps it, the retry books with the provider's own ids.
    $row->forceFill(['is_mapped' => true])->save();
    $retried = app(RetryShipment::class)->handle($shipment);
    expect($retried->status)->toBe(ShipmentStatus::Booked)
        ->and($pathao->booked[0]->courierLocation?->externalZoneId)->toBe('10');
    cbInvariants();
});

it('blocks an ambiguous mapping until staff choose the zone', function (): void {
    $pathao = CourierFixtures::install('pathao', requiresLocationMapping: true);
    $this->seed(Database\Seeders\GeographySeeder::class);
    $upazilaId = 365;
    $order = cbConfirmed([$this->a->id => 1], ['shipping_upazila_id' => $upazilaId]);
    $provider = App\Domain\Fulfilment\Models\CourierProvider::query()->where('code', 'pathao')->sole();
    foreach (['10', '11'] as $zone) {
        CourierLocation::query()->create([
            'provider_id' => $provider->id, 'external_key' => "1:{$zone}:-", 'external_city_id' => '1', 'external_city_name' => 'Dhaka',
            'external_zone_id' => $zone, 'external_zone_name' => "Zone {$zone}", 'upazila_id' => $upazilaId, 'is_mapped' => true, 'is_active' => true,
        ]);
    }

    $shipment = app(RequestShipment::class)->handle($order->id, 'pathao')->refresh();
    expect($shipment->failure_code)->toBe(Shipment::FAILURE_AMBIGUOUS);

    app(CancelShipment::class)->handle($shipment, 'choose zone');
    $chosen = CourierLocation::query()->where('external_zone_id', '11')->sole();
    $again = app(RequestShipment::class)->handle($order->id, 'pathao', $chosen->id)->refresh();
    expect($again->status)->toBe(ShipmentStatus::Booked)
        ->and($pathao->booked[0]->courierLocation?->externalZoneId)->toBe('11');
});

it('books in bulk as submitted with no consignment ids until a status confirms each parcel (rule 24d)', function (): void {
    $one = cbConfirmed([$this->a->id => 1]);
    $two = cbConfirmed([$this->b->id => 2]);
    $pending = OrderFixtures::place([$this->a->id => 1]);

    $outcomes = app(RequestShipments::class)->handle([$one->id, $two->id, $pending->id, 999999]);

    expect(array_map(fn ($o) => $o->result, $outcomes))->toBe(['requested', 'requested', 'conflict', 'not_found'])
        ->and($this->courier->bulkBooked)->toHaveCount(1)
        ->and($this->courier->bulkBooked[0])->toHaveCount(2)
        ->and($this->courier->booked)->toBe([]);

    $shipments = Shipment::query()->orderBy('id')->get();
    foreach ($shipments as $s) {
        expect($s->status)->toBe(ShipmentStatus::Submitted)
            ->and($s->consignment_id)->toBeNull()
            ->and($s->isDispatched())->toBeFalse();
    }
    // Orders untouched: still confirmed, stock still reserved, no charge.
    expect($one->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(cbLedger($one->id))->toBe([]);
    cbInvariants();

    // The poll/webhook resolves the first parcel's id and status.
    app(ApplyCourierStatus::class)->handle($shipments[0], new CourierStatusResult(
        ShipmentStatus::PickedUp, 'picked_up', charge: Money::of('80.00'), consignmentId: 'SF-1001', trackingCode: 'ab12cd34',
    ), 'webhook');

    $first = $shipments[0]->refresh();
    expect($first->status)->toBe(ShipmentStatus::PickedUp)
        ->and($first->consignment_id)->toBe('SF-1001')
        ->and($first->isDispatched())->toBeTrue()
        ->and($one->refresh()->status)->toBe(OrderStatus::Shipped)
        ->and(cbLedger($one->id))->toBe(['shipment:courier_charge:out:80.00:cash:rec'])
        ->and($two->refresh()->status)->toBe(OrderStatus::Confirmed);
    cbInvariants();
});

it('fails each shipment of a refused bulk submission', function (): void {
    $one = cbConfirmed([$this->a->id => 1]);
    $this->courier->willBookMany(new BulkBookingResult(false, 'Balance too low'));

    app(RequestShipments::class)->handle([$one->id]);

    $s = Shipment::query()->sole();
    expect($s->status)->toBe(ShipmentStatus::Failed)
        ->and($s->failure_code)->toBe(Shipment::FAILURE_REFUSED)
        ->and($s->error_message)->toBe('Balance too low');
});

it('opens the circuit after consecutive transport failures and releases jobs without calling out', function (): void {
    config(['couriers.circuit_breaker.failure_threshold' => 2, 'couriers.booking.tries' => 5]);
    $breaker = app(CourierCircuitBreaker::class);
    $order = cbConfirmed([$this->a->id => 1]);
    $this->courier->willBook(new RuntimeException('timeout'), new RuntimeException('timeout'));

    $shipment = app(RequestShipment::class)->handle($order->id, dispatch: false);

    // Attempt 1 and 2 fail in transport: the job releases itself, the shipment stays pending.
    (new BookShipment($shipment->id))->handle(...cbJobDeps());
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($breaker->isOpen('steadfast'))->toBeFalse()
        ->and($breaker->failures('steadfast'))->toBe(1);
    (new BookShipment($shipment->id))->handle(...cbJobDeps());
    expect($breaker->isOpen('steadfast'))->toBeTrue();

    // While open: no call at all.
    $calls = count($this->courier->booked);
    (new BookShipment($shipment->id))->handle(...cbJobDeps());
    expect($this->courier->booked)->toHaveCount($calls)
        ->and($shipment->refresh()->status)->toBe(ShipmentStatus::Pending);

    // Closed again (time passes): the next run books, and success resets the breaker.
    $this->travel(10)->minutes();
    (new BookShipment($shipment->id))->handle(...cbJobDeps());
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($breaker->failures('steadfast'))->toBe(0)
        ->and($breaker->isOpen('steadfast'))->toBeFalse();
    cbInvariants();
});

it('fails as transport after the last try', function (): void {
    config(['couriers.booking.tries' => 1, 'couriers.circuit_breaker.failure_threshold' => 10]);
    $order = cbConfirmed([$this->a->id => 1]);
    $this->courier->willBook(new RuntimeException('connection refused'));

    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();

    expect($shipment->status)->toBe(ShipmentStatus::Failed)
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_TRANSPORT)
        ->and($order->refresh()->status)->toBe(OrderStatus::Confirmed);
});

it('never books twice when the booking job runs twice', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id, dispatch: false);

    BookShipment::dispatchSync($shipment->id);
    BookShipment::dispatchSync($shipment->id);
    (new BookShipment($shipment->id))->handle(...cbJobDeps());

    expect($this->courier->booked)->toHaveCount(1)
        ->and($shipment->refresh()->booking_attempts)->toBe(1)
        ->and(StockMovement::query()->where('type', StockMovementType::SaleOut->value)->count())->toBe(1)
        ->and(FinancialTransaction::query()->where('type', LedgerEntryType::CourierCharge->value)->count())->toBe(1);
    cbInvariants();
});

it('keeps the consignment when the order was cancelled while the courier call was in flight, and retries without re-booking', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id, dispatch: false);

    // Claim + call happen, then the order is cancelled before completion.
    $request = app(App\Domain\Fulfilment\Services\BookingPreflight::class)
        ->check($shipment->refresh(), $shipment->provider, $this->courier)->request;
    app(App\Domain\Fulfilment\Actions\ClaimShipmentForBooking::class)->handle($shipment->id, $request);
    $result = $this->courier->book($request);
    app(CancelOrder::class)->handle($order->refresh(), CancellationReason::cases()[0]);
    app(App\Domain\Fulfilment\Actions\CompleteShipmentBooking::class)->handle($shipment->id, $result);

    $shipment->refresh();
    expect($shipment->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->consignment_id)->toBe($result->consignmentId)
        ->and($shipment->isDispatched())->toBeFalse()
        ->and($shipment->failure_code)->toBe(Shipment::FAILURE_COMPLETION)
        ->and(Shipment::query()->needsAttention()->pluck('id')->all())->toBe([$shipment->id])
        ->and($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and(cbLedger($order->id))->toBe([]);
    cbInvariants();

    // Retry completes locally only — no second courier call (and here the order still refuses).
    $calls = count($this->courier->booked);
    app(RetryShipment::class)->handle($shipment);
    expect($this->courier->booked)->toHaveCount($calls)
        ->and($shipment->refresh()->failure_code)->toBe(Shipment::FAILURE_COMPLETION);
});

it('handles a courier return: courier_return_in at snapshot cost, order returned, COD cancelled, return charge; courier charge stays', function (): void {
    $order = cbConfirmed([$this->a->id => 2]);
    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::InTransit, 'in_transit'));
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::Returned, 'returned'));

    $shipment->refresh();
    $order->refresh()->load('items', 'payments');
    expect($shipment->status)->toBe(ShipmentStatus::Returned)
        ->and($shipment->return_charge?->toDecimal())->toBe('50.00')
        ->and($order->status)->toBe(OrderStatus::Returned)
        ->and($order->fulfillment_status)->toBe(FulfillmentStatus::Returned)
        ->and($order->payment_status)->toBe(OrderPaymentStatus::Unpaid)
        ->and($order->payments->first()->status)->toBe(PaymentStatus::Cancelled)
        ->and($order->items->first()->quantity_returned)->toBe(2)
        ->and(cbLevel($this->a->id)->on_hand)->toBe(10);
    $in = StockMovement::query()->where('type', StockMovementType::CourierReturnIn->value)->sole();
    expect($in->quantity)->toBe(2);

    // Nothing was recognised (never delivered): only the two courier costs.
    expect(cbLedger($order->id))->toEqualCanonicalizing([
        'shipment:courier_charge:out:70.00:cash:rec',
        'shipment:courier_return_charge:out:50.00:cash:rec',
    ]);
    cbInvariants();
});

it('reverses recognised revenue when a delivered order comes back as a courier return', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    // Staff marked it delivered by hand while the parcel was still moving.
    app(App\Domain\Ordering\Actions\MarkOrderDelivered::class)->handle($order->refresh());
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::Returned, 'returned'));

    expect($order->refresh()->status)->toBe(OrderStatus::Returned)
        ->and(cbLedger($order->id))->toEqualCanonicalizing([
            'shipment:courier_charge:out:70.00:cash:rec',
            'order:sale_revenue:in:600.00:nocash:rec',
            'order:cogs:out:350.00:nocash:rec',
            'order:shipping_revenue:in:70.00:nocash:rec',
            'shipment:courier_return_charge:out:50.00:cash:rec',
            'order_reversal:sale_revenue:out:600.00:nocash:rec',
            'order_reversal:cogs:in:350.00:nocash:rec',
            'order_reversal:shipping_revenue:out:70.00:nocash:rec',
        ]);
    cbInvariants();
});

it('ignores a late status the transition table refuses, and is idempotent on a replay', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    $delivered = new CourierStatusResult(ShipmentStatus::Delivered, 'delivered');
    app(ApplyCourierStatus::class)->handle($shipment, $delivered);
    app(ApplyCourierStatus::class)->handle($shipment, $delivered);
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::InTransit, 'in_transit'));

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($shipment->provider_status_raw)->toBe('delivered')
        ->and($order->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and(FinancialTransaction::query()->where('type', LedgerEntryType::SaleRevenue->value)->count())->toBe(1);
    cbInvariants();
});

it('records a partial delivery on the shipment and flags the order for staff', function (): void {
    $order = cbConfirmed([$this->a->id => 1, $this->b->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::PartiallyDelivered, 'partial_delivered', codCollected: Money::of('600.00')));

    expect($shipment->refresh()->status)->toBe(ShipmentStatus::PartiallyDelivered)
        ->and($shipment->cod_collected?->toDecimal())->toBe('600.00')
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipped)
        ->and(DB::table('order_notes')->where('order_id', $order->id)->value('body'))->toContain('PARTIAL');
    cbInvariants();
});

it('cancels only a shipment no courier holds', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $pending = app(RequestShipment::class)->handle($order->id, dispatch: false);
    expect(app(CancelShipment::class)->handle($pending)->status)->toBe(ShipmentStatus::Cancelled);

    $booked = app(RequestShipment::class)->handle($order->id)->refresh();
    expect(fn () => app(CancelShipment::class)->handle($booked))->toThrow(InvalidShipmentTransition::class);
});

it('refuses to delete a shipment', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $shipment = app(RequestShipment::class)->handle($order->id, dispatch: false);

    expect(fn () => $shipment->delete())->toThrow(App\Domain\Fulfilment\Exceptions\ShipmentViolation::class);
});

/** @return list<object> */
function cbJobDeps(): array
{
    return [
        app(App\Domain\Fulfilment\Services\CourierRegistry::class),
        app(CourierCircuitBreaker::class),
        app(App\Domain\Fulfilment\Services\BookingPreflight::class),
        app(App\Domain\Fulfilment\Actions\ClaimShipmentForBooking::class),
        app(App\Domain\Fulfilment\Actions\CompleteShipmentBooking::class),
        app(App\Domain\Fulfilment\Actions\FailShipment::class),
    ];
}

it('never re-posts when the courier may have booked despite an unreadable answer: submitted until confirmed', function (): void {
    $order = cbConfirmed([$this->a->id => 1]);
    $uncertain = new class('unreadable 2xx') extends RuntimeException implements App\Domain\Fulfilment\Contracts\ReportsUncertainBooking
    {
        public function bookingMayHaveSucceeded(): bool
        {
            return true;
        }
    };
    $this->courier->willBook($uncertain);

    $shipment = app(RequestShipment::class)->handle($order->id)->refresh();

    expect($shipment->status)->toBe(ShipmentStatus::Submitted)
        ->and($shipment->consignment_id)->toBeNull()
        ->and($this->courier->booked)->toHaveCount(1)
        ->and(app(CourierCircuitBreaker::class)->failures('steadfast'))->toBe(0)
        ->and($order->refresh()->status)->toBe(OrderStatus::Confirmed);

    // A second run of the job does not re-post.
    BookShipment::dispatchSync($shipment->id);
    expect($this->courier->booked)->toHaveCount(1);

    // The webhook/poll confirms it: completed then.
    app(ApplyCourierStatus::class)->handle($shipment, new CourierStatusResult(ShipmentStatus::Booked, 'in_review', charge: Money::of('70.00'), consignmentId: 'PTH-9'));
    expect($shipment->refresh()->status)->toBe(ShipmentStatus::Booked)
        ->and($shipment->consignment_id)->toBe('PTH-9')
        ->and($order->refresh()->status)->toBe(OrderStatus::Shipped);
    cbInvariants();
});
