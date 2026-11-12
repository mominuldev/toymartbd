<?php

declare(strict_types=1);

use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderEventType;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Events\OrderCompleted;
use App\Domain\Ordering\Events\OrderConfirmed;
use App\Domain\Ordering\Events\OrderDelivered;
use App\Domain\Ordering\Events\OrderFulfillmentStatusChanged;
use App\Domain\Ordering\Events\OrderPartiallyReturned;
use App\Domain\Ordering\Events\OrderPaymentStatusChanged;
use App\Domain\Ordering\Events\OrderProcessingStarted;
use App\Domain\Ordering\Events\OrderPutOnHold;
use App\Domain\Ordering\Events\OrderReadyToShip;
use App\Domain\Ordering\Events\OrderReleasedFromHold;
use App\Domain\Ordering\Events\OrderReturned;
use App\Domain\Ordering\Events\OrderShipped;
use App\Domain\Ordering\Events\OrderStatusTransition;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Ordering\Services\OrderTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * P4-ORD-03 — the order state machine, exhaustively: every (from, to) pair of every
 * status column is tried; allowed pairs write one order_event and emit their event,
 * every other pair is refused and leaves the order untouched.
 */

/** The documented table. A change to OrderStatus::allowedTransitions() must change this too. */
const ORDER_STATUS_TABLE = [
    'pending' => ['confirmed', 'on_hold', 'cancelled'],
    'on_hold' => ['pending', 'confirmed', 'cancelled'],
    'confirmed' => ['processing', 'ready_to_ship', 'shipped', 'on_hold', 'cancelled'],
    'processing' => ['ready_to_ship', 'shipped', 'on_hold', 'cancelled'],
    'ready_to_ship' => ['shipped', 'on_hold', 'cancelled'],
    'shipped' => ['delivered', 'returned', 'partially_returned'],
    'delivered' => ['completed', 'returned', 'partially_returned'],
    'partially_returned' => ['returned', 'completed'],
    'completed' => [],
    'cancelled' => [],
    'returned' => [],
];

const ORDER_STATUS_EVENTS = [
    'pending' => OrderReleasedFromHold::class,
    'on_hold' => OrderPutOnHold::class,
    'confirmed' => OrderConfirmed::class,
    'processing' => OrderProcessingStarted::class,
    'ready_to_ship' => OrderReadyToShip::class,
    'shipped' => OrderShipped::class,
    'delivered' => OrderDelivered::class,
    'completed' => OrderCompleted::class,
    'cancelled' => OrderCancelled::class,
    'returned' => OrderReturned::class,
    'partially_returned' => OrderPartiallyReturned::class,
];

/** @return array<string, array{OrderStatus, OrderStatus, bool}> */
function ordEveryStatusPair(): array
{
    $pairs = [];
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            $allowed = in_array($to->value, ORDER_STATUS_TABLE[$from->value], true);
            $pairs["{$from->value} → {$to->value}".($allowed ? ' (allowed)' : ' (refused)')] = [$from, $to, $allowed];
        }
    }

    return $pairs;
}

it('implements exactly the documented transition table', function (): void {
    foreach (OrderStatus::cases() as $status) {
        expect(array_map(fn (OrderStatus $s): string => $s->value, $status->allowedTransitions()))
            ->toEqualCanonicalizing(ORDER_STATUS_TABLE[$status->value]);
    }
});

it('handles status transition', function (OrderStatus $from, OrderStatus $to, bool $allowed): void {
    Event::fake();
    $order = Order::factory()->status($from)->create();

    $attempt = fn () => DB::transaction(fn () => app(OrderTransitions::class)->transition($order, $to, null));

    if (! $allowed) {
        expect($attempt)->toThrow(InvalidOrderTransition::class);
        expect($order->refresh()->status)->toBe($from)
            ->and(OrderEvent::query()->count())->toBe(0);
        Event::assertNotDispatched(OrderStatusTransition::class);

        return;
    }

    $attempt();
    $order->refresh();
    $event = OrderEvent::query()->sole();

    expect($order->status)->toBe($to)
        ->and($event->type)->toBe(OrderEventType::StatusChanged)
        ->and($event->from_status)->toBe($from->value)
        ->and($event->to_status)->toBe($to->value)
        ->and($event->is_customer_visible)->toBe($to->isCustomerVisible());

    if (($column = $to->timestampColumn()) !== null) {
        expect($order->getAttribute($column))->not->toBeNull();
    }

    Event::assertDispatchedTimes(ORDER_STATUS_EVENTS[$to->value], 1);
    Event::assertDispatched(ORDER_STATUS_EVENTS[$to->value], fn ($e): bool => $e->orderId === $order->id
        && $e->customerId === $order->customer_id && $e->from === $from->value && $e->to === $to->value);
})->with(ordEveryStatusPair());

it('handles payment_status transition', function (OrderPaymentStatus $from, OrderPaymentStatus $to): void {
    Event::fake();
    $order = Order::factory()->create(['payment_status' => $from]);
    $attempt = fn () => DB::transaction(fn () => app(OrderTransitions::class)->transitionPayment($order, $to));

    if (! $from->canTransitionTo($to)) {
        expect($attempt)->toThrow(InvalidOrderTransition::class);
        expect($order->refresh()->payment_status)->toBe($from);
        Event::assertNotDispatched(OrderPaymentStatusChanged::class);

        return;
    }

    $attempt();
    expect($order->refresh()->payment_status)->toBe($to)
        ->and(OrderEvent::query()->sole()->type)->toBe(OrderEventType::PaymentStatusChanged);
    Event::assertDispatched(OrderPaymentStatusChanged::class, fn ($e): bool => $e->from === $from->value && $e->to === $to->value);
})->with(function (): array {
    $pairs = [];
    foreach (OrderPaymentStatus::cases() as $from) {
        foreach (OrderPaymentStatus::cases() as $to) {
            $pairs["{$from->value} → {$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
});

it('handles fulfillment_status transition', function (FulfillmentStatus $from, FulfillmentStatus $to): void {
    Event::fake();
    $order = Order::factory()->create(['fulfillment_status' => $from]);
    $attempt = fn () => DB::transaction(fn () => app(OrderTransitions::class)->transitionFulfillment($order, $to));

    if (! $from->canTransitionTo($to)) {
        expect($attempt)->toThrow(InvalidOrderTransition::class);
        expect($order->refresh()->fulfillment_status)->toBe($from);

        return;
    }

    $attempt();
    expect($order->refresh()->fulfillment_status)->toBe($to);
    Event::assertDispatched(OrderFulfillmentStatusChanged::class);
})->with(function (): array {
    $pairs = [];
    foreach (FulfillmentStatus::cases() as $from) {
        foreach (FulfillmentStatus::cases() as $to) {
            $pairs["{$from->value} → {$to->value}"] = [$from, $to];
        }
    }

    return $pairs;
});

it('refuses a status change that bypasses the state machine', function (): void {
    $order = Order::factory()->create();

    expect(fn () => $order->update(['status' => OrderStatus::Delivered]))->toThrow(OrderViolation::class)
        ->and(fn () => $order->update(['payment_status' => OrderPaymentStatus::Paid]))->toThrow(OrderViolation::class);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

// The "outside a transaction" guard (OrderViolation::outsideTransaction) cannot be exercised
// here: RefreshDatabase wraps every test in an open transaction, as for StockReservations.

it('never deletes an order', function (): void {
    $order = Order::factory()->create();

    expect(fn () => $order->delete())->toThrow(OrderViolation::class);
    expect(Order::query()->count())->toBe(1);
});

it('keeps order_events append-only', function (): void {
    $order = Order::factory()->create();
    DB::transaction(fn () => app(OrderTransitions::class)->transition($order, OrderStatus::Confirmed));
    $event = OrderEvent::query()->sole();

    expect(fn () => $event->update(['to_status' => 'delivered']))->toThrow(OrderViolation::class)
        ->and(fn () => $event->delete())->toThrow(OrderViolation::class);
});

it('rejects the second of two identical clicks (a race) with a 409', function (): void {
    $order = Order::factory()->create();
    app(TransitionOrder::class)->handle($order, OrderStatus::Confirmed);

    expect(fn () => app(TransitionOrder::class)->handle($order, OrderStatus::Confirmed))->toThrow(InvalidOrderTransition::class);
    expect(OrderEvent::query()->count())->toBe(1);
});

it('lets TransitionOrder make only side-effect-free moves', function (OrderStatus $to): void {
    $order = Order::factory()->status(OrderStatus::Confirmed)->create();

    expect(fn () => app(TransitionOrder::class)->handle($order, $to))->toThrow(InvalidArgumentException::class);
})->with([OrderStatus::Cancelled, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Returned]);

it('rolls the status back and dispatches nothing when the surrounding transaction fails', function (): void {
    Event::fake([OrderConfirmed::class]);
    $order = Order::factory()->create();

    try {
        DB::transaction(function () use ($order): void {
            app(OrderTransitions::class)->transition($order, OrderStatus::Confirmed);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and(OrderEvent::query()->count())->toBe(0);
    Event::assertNotDispatched(OrderConfirmed::class);
});
