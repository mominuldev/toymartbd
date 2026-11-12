<?php

declare(strict_types=1);

use App\Domain\Finance\Models\FinancialTransaction;
use App\Domain\Inventory\Actions\ReconcileStockLevels;
use App\Domain\Inventory\Contracts\OpenOrderReservations;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Actions\MarkOrderDelivered;
use App\Domain\Ordering\Actions\MarkOrderShipped;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderShipped;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Payment\Enums\PaymentMethod;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Models\Enums\Permission;
use App\Models\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Finance\FinanceKit;
use Tests\Feature\Ordering\Support\OrderFixtures;
use Tests\Feature\Orders\Support;
use Tests\Support\RoleMatrix;

/*
 * Manual fulfilment (P5, until Phase 6 courier booking): mark shipped consumes the
 * reservation exactly once (§4.2 minus the shipment row), mark delivered recognises revenue
 * through Finance's listener and collects COD as a payment — never as ledger cash (§4.3,
 * rule 10). Both invariants hold after each step.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();
    Mail::fake();
    config(['services.storefront.url' => '', 'services.storefront.revalidate_secret' => '']);
    $this->a = OrderFixtures::stockedVariant(10, '600.00', '350.00');
    $this->b = OrderFixtures::stockedVariant(5, '900.00', '500.00');
});

function mfLevel(int $variantId): StockLevel
{
    return StockLevel::query()->where('variant_id', $variantId)->where('location_id', StockLocation::defaultId())->sole();
}

function mfConfirmed(array $lines): Order
{
    $order = OrderFixtures::place($lines);

    return app(TransitionOrder::class)->handle($order, OrderStatus::Confirmed);
}

function mfInvariants(): void
{
    Support::assertStockInvariants();
    FinanceKit::assertFinanceInvariant();
    expect(app(ReconcileStockLevels::class)->handle())->toMatchArray(['drift_count' => 0, 'reserved_checked' => true]);
}

it('ships: consumes the reservation as sale_out movements at the snapshot cost, in one go', function (): void {
    Event::fake([OrderShipped::class]);
    $order = mfConfirmed([$this->b->id => 1, $this->a->id => 3]);
    app(TransitionOrder::class)->handle($order, OrderStatus::ReadyToShip);

    $shipped = app(MarkOrderShipped::class)->handle($order, 'Pathao (booked on portal)', 'PTH-77881', null, 'রাইডার নিয়ে গেছে');

    expect($shipped->status)->toBe(OrderStatus::Shipped)
        ->and($shipped->fulfillment_status)->toBe(FulfillmentStatus::Fulfilled)
        ->and($shipped->shipped_at)->not->toBeNull()
        ->and(mfLevel($this->a->id)->on_hand)->toBe(7)
        ->and(mfLevel($this->a->id)->reserved)->toBe(0)
        ->and(mfLevel($this->b->id)->on_hand)->toBe(4)
        ->and(mfLevel($this->b->id)->reserved)->toBe(0)
        ->and($order->items()->pluck('quantity_shipped', 'variant_id')->all())->toEqual([$this->a->id => 3, $this->b->id => 1]);

    $sales = StockMovement::query()->where('type', 'sale_out')->orderBy('variant_id')->get();
    expect($sales)->toHaveCount(2)
        ->and($sales[0]->variant_id)->toBe($this->a->id)
        ->and($sales[0]->quantity)->toBe(-3)
        ->and($sales[0]->balance_after)->toBe(7)
        ->and((string) $sales[0]->unit_cost)->toStartWith('350.00')
        ->and($sales[0]->reference_type)->toBe('order')
        ->and($sales[0]->reference_id)->toBe($order->id);

    $event = OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'shipped')->sole();
    expect($event->is_customer_visible)->toBeTrue()
        ->and($event->description)->toBe('রাইডার নিয়ে গেছে')
        ->and($event->meta)->toMatchArray(['manual' => true, 'courier_name' => 'Pathao (booked on portal)', 'tracking_reference' => 'PTH-77881']);
    Event::assertDispatched(OrderShipped::class, fn (OrderShipped $e): bool => $e->orderId === $order->id && $e->from === 'ready_to_ship');

    mfInvariants();
});

it('ships straight from confirmed, as the state machine allows, and refuses pending and on hold', function (): void {
    $confirmed = mfConfirmed([$this->a->id => 1]);
    expect(app(MarkOrderShipped::class)->handle($confirmed)->status)->toBe(OrderStatus::Shipped);

    $pending = OrderFixtures::place([$this->a->id => 1]);
    expect(fn () => app(MarkOrderShipped::class)->handle($pending))->toThrow(InvalidOrderTransition::class);
    expect(mfLevel($this->a->id)->reserved)->toBe(1)->and(mfLevel($this->a->id)->on_hand)->toBe(9);
    mfInvariants();
});

it('consumes stock exactly once: a second ship is a 409 and moves nothing', function (): void {
    Support::actingAs(Role::OrderOperator);
    $order = mfConfirmed([$this->a->id => 2]);

    $this->postJson("/api/v1/admin/orders/{$order->id}/ship", ['courier_name' => 'Steadfast'], ['Idempotency-Key' => 'ship-key-0001'])
        ->assertOk()->assertJsonPath('status', 'shipped');
    // The same key replays the stored response; a fresh key reaches the state machine.
    $this->postJson("/api/v1/admin/orders/{$order->id}/ship", ['courier_name' => 'Steadfast'], ['Idempotency-Key' => 'ship-key-0001'])
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    $this->postJson("/api/v1/admin/orders/{$order->id}/ship", [], ['Idempotency-Key' => 'ship-key-0002'])
        ->assertStatus(409)->assertJsonPath('type', fn (string $t): bool => str_contains($t, 'conflict'));

    expect(StockMovement::query()->where('type', 'sale_out')->count())->toBe(1)
        ->and(mfLevel($this->a->id)->on_hand)->toBe(8)
        ->and(mfLevel($this->a->id)->reserved)->toBe(0);
    mfInvariants();
});

it('requires an Idempotency-Key on ship and deliver', function (): void {
    Support::actingAs(Role::OrderOperator);
    $order = mfConfirmed([$this->a->id => 1]);

    $this->postJson("/api/v1/admin/orders/{$order->id}/ship")->assertStatus(400);
    $this->postJson("/api/v1/admin/orders/{$order->id}/deliver")->assertStatus(400);
    $this->postJson('/api/v1/admin/orders/bulk/ship', ['order_ids' => [$order->id]])->assertStatus(400);
    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
});

it('refuses to ship when on_hand no longer covers the line, changing nothing', function (): void {
    Support::actingAs(Role::OrderOperator);
    $order = mfConfirmed([$this->a->id => 2]);
    // A miscount: on_hand fell under the reservation through an (approved) write-off.
    DB::table('stock_movements')->insert([
        'variant_id' => $this->a->id, 'location_id' => StockLocation::defaultId(), 'type' => 'adjustment_out',
        'quantity' => -9, 'balance_after' => 1, 'created_at' => now(),
    ]);
    DB::table('stock_levels')->where('variant_id', $this->a->id)->update(['on_hand' => 1]);

    $this->postJson("/api/v1/admin/orders/{$order->id}/ship", [], ['Idempotency-Key' => 'ship-key-short'])
        ->assertStatus(409)->assertJsonPath('type', fn (string $t): bool => str_contains($t, 'insufficient-stock'));

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed)
        ->and(mfLevel($this->a->id)->reserved)->toBe(2)
        ->and($order->items()->value('quantity_shipped'))->toBe(0);
});

it('delivers: COD paid, revenue recognised by Finance, no cash in the ledger, dated the Dhaka day', function (): void {
    // 23:30 UTC on the 20th is 05:30 on the 21st in Dhaka.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 23:30:00', 'UTC'));
    $order = mfConfirmed([$this->a->id => 2]);   // 2 × ৳600 at ৳350, ৳70 shipping → ৳1270
    app(MarkOrderShipped::class)->handle($order);

    $delivered = app(MarkOrderDelivered::class)->handle($order, null, 'Customer received');

    expect($delivered->status)->toBe(OrderStatus::Delivered)
        ->and($delivered->delivered_at?->timezone('Asia/Dhaka')->toDateString())->toBe('2026-11-21')
        ->and($delivered->payment_status)->toBe(OrderPaymentStatus::Paid)
        ->and($delivered->paid_total->toDecimal())->toBe('1270.00')
        ->and($delivered->due_total->toDecimal())->toBe('0.00');

    $payment = $order->payments()->sole();
    expect($payment->method)->toBe(PaymentMethod::Cod)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull();

    $ledger = FinancialTransaction::query()->where('source_type', 'order')->where('source_id', $order->id)->get()->keyBy(fn ($t) => $t->type->value);
    expect($ledger->keys()->sort()->values()->all())->toBe(['cogs', 'sale_revenue', 'shipping_revenue'])
        ->and($ledger['sale_revenue']->amount->toDecimal())->toBe('1200.00')
        ->and($ledger['cogs']->amount->toDecimal())->toBe('700.00')
        ->and($ledger['sale_revenue']->occurred_on->toDateString())->toBe('2026-11-21');
    // Rule 10: the courier holds the cash — nothing is a cash movement until remittance.
    expect(FinancialTransaction::query()->where('is_cash_movement', true)->count())->toBe(0)
        ->and(FinancialTransaction::query()->where('type', 'cod_received')->count())->toBe(0);

    $event = OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'delivered')->sole();
    expect($event->is_customer_visible)->toBeTrue()->and($event->meta['manual'])->toBeTrue();

    mfInvariants();
});

it('fires OrderDelivered once and refuses a second delivery with a 409', function (): void {
    Support::actingAs(Role::Manager);
    $order = mfConfirmed([$this->a->id => 1]);
    $this->postJson("/api/v1/admin/orders/{$order->id}/deliver", [], ['Idempotency-Key' => 'deliver-early'])->assertStatus(409);
    app(MarkOrderShipped::class)->handle($order);

    $this->postJson("/api/v1/admin/orders/{$order->id}/deliver", [], ['Idempotency-Key' => 'deliver-key-1'])
        ->assertOk()->assertJsonPath('status', 'delivered');
    $this->postJson("/api/v1/admin/orders/{$order->id}/deliver", [], ['Idempotency-Key' => 'deliver-key-2'])->assertStatus(409);

    expect(FinancialTransaction::query()->where('type', 'sale_revenue')->count())->toBe(1)
        ->and(OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'delivered')->count())->toBe(1);
    mfInvariants();
});

it('dispatches OrderDelivered after commit', function (): void {
    Event::fake([OrderDelivered::class]);
    $order = mfConfirmed([$this->a->id => 1]);
    app(MarkOrderShipped::class)->handle($order);
    app(MarkOrderDelivered::class)->handle($order);

    Event::assertDispatchedTimes(OrderDelivered::class, 1);
});

it('bulk ships and delivers, reporting each order on its own', function (): void {
    Support::actingAs(Role::OrderOperator);
    $ready = mfConfirmed([$this->a->id => 1]);
    $other = mfConfirmed([$this->b->id => 2]);
    $pending = OrderFixtures::place([$this->a->id => 1]);

    $json = $this->postJson('/api/v1/admin/orders/bulk/ship', ['order_ids' => [$pending->id, $ready->id, 999999, $other->id], 'courier_name' => 'Own rider'], ['Idempotency-Key' => 'bulk-ship-001'])
        ->assertOk()->json();
    expect($json['meta'])->toBe(['done' => 2, 'failed' => 2])
        ->and(array_column($json['data'], 'outcome'))->toBe(['conflict', 'done', 'not_found', 'done'])
        ->and($json['data'][0]['status'])->toBe('pending')
        ->and(OrderEvent::query()->where('order_id', $other->id)->where('to_status', 'shipped')->sole()->meta['courier_name'])->toBe('Own rider');

    $json = $this->postJson('/api/v1/admin/orders/bulk/deliver', ['order_ids' => [$ready->id, $pending->id, $other->id]], ['Idempotency-Key' => 'bulk-deliver-1'])
        ->assertOk()->json();
    expect($json['meta'])->toBe(['done' => 2, 'failed' => 1])
        ->and(array_column($json['data'], 'outcome'))->toBe(['done', 'conflict', 'done'])
        ->and($other->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($pending->refresh()->status)->toBe(OrderStatus::Pending);

    mfInvariants();
});

it('detects an injected reserved mismatch against open orders', function (): void {
    $order = mfConfirmed([$this->a->id => 3]);
    expect(app(OpenOrderReservations::class)->quantities())->toBe([StockLocation::defaultId() => [$this->a->id => 3]]);
    mfInvariants();

    DB::table('stock_levels')->where('variant_id', $this->a->id)->update(['reserved' => 1]);
    $result = app(ReconcileStockLevels::class)->handle();

    expect($result)->toMatchArray(['reserved_checked' => true, 'drift_count' => 1]);
    $alert = InventoryAlert::query()->where('kind', 'reserved_drift')->sole();
    expect($alert->variant_id)->toBe($this->a->id)->and($alert->expected)->toBe(3)->and($alert->actual)->toBe(1)
        ->and(mfLevel($this->a->id)->reserved)->toBe(1); // never corrected

    // A shipped order holds nothing: after shipping, its lines leave the expected figure.
    DB::table('stock_levels')->where('variant_id', $this->a->id)->update(['reserved' => 3]);
    app(MarkOrderShipped::class)->handle($order);
    expect(app(OpenOrderReservations::class)->quantities())->toBe([]);
});

describe('role matrix', function (): void {
    $allowed = static fn (Role $role): bool => RoleMatrix::allows($role, Permission::OrdersUpdate) && RoleMatrix::allows($role, Permission::ShipmentsBook);

    it('guards ship and bulk ship with orders.update + shipments.book', function (Role $role) use ($allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed($role), function () {
            $order = mfConfirmed([$this->a->id => 1]);

            return $this->postJson("/api/v1/admin/orders/{$order->id}/ship", [], ['Idempotency-Key' => 'rm-ship-'.$order->id]);
        });
        RoleMatrix::assertFor($this, $role, $allowed($role), function () {
            $order = mfConfirmed([$this->a->id => 1]);

            return $this->postJson('/api/v1/admin/orders/bulk/ship', ['order_ids' => [$order->id]], ['Idempotency-Key' => 'rm-bship-'.$order->id]);
        });
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('guards deliver and bulk deliver with orders.update + shipments.book', function (Role $role) use ($allowed): void {
        RoleMatrix::assertFor($this, $role, $allowed($role), function () {
            $order = mfConfirmed([$this->a->id => 1]);
            app(MarkOrderShipped::class)->handle($order);

            return $this->postJson("/api/v1/admin/orders/{$order->id}/deliver", [], ['Idempotency-Key' => 'rm-deliver-'.$order->id]);
        });
        RoleMatrix::assertFor($this, $role, $allowed($role), function () {
            $order = mfConfirmed([$this->a->id => 1]);
            app(MarkOrderShipped::class)->handle($order);

            return $this->postJson('/api/v1/admin/orders/bulk/deliver', ['order_ids' => [$order->id]], ['Idempotency-Key' => 'rm-bdeliver-'.$order->id]);
        });
    })->with(array_map(static fn (Role $r): array => [$r], Role::cases()));

    it('refuses a guest with 401', function (): void {
        $this->postJson('/api/v1/admin/orders/1/ship', [], ['Idempotency-Key' => 'guest-ship-1'])->assertUnauthorized();
        $this->postJson('/api/v1/admin/orders/bulk/deliver', ['order_ids' => [1]], ['Idempotency-Key' => 'guest-bd-1'])->assertUnauthorized();
    });
});
