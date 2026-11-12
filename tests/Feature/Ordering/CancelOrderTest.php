<?php

declare(strict_types=1);

use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Ordering\Actions\CancelOrder;
use App\Domain\Ordering\Actions\TransitionOrder;
use App\Domain\Ordering\Enums\CancellationReason;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Events\OrderCancelled;
use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use App\Domain\Payment\Enums\PaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Ordering\Support\OrderFixtures;

/*
 * P4-ORD-09 — cancel before shipping: reason captured, reservation released through
 * Inventory's ReleaseStock, open payments cancelled, one event row, OrderCancelled for
 * the coupon (Marketing) and risk (Risk) listeners.
 */

function ordReserved(int $variantId): int
{
    return StockLevel::query()->where('variant_id', $variantId)->value('reserved');
}

beforeEach(function (): void {
    $this->a = OrderFixtures::stockedVariant(10);
    $this->b = OrderFixtures::stockedVariant(5);
});

it('releases the reservation of every line and records the reason', function (): void {
    $order = OrderFixtures::place([$this->b->id => 2, $this->a->id => 3]);
    expect(ordReserved($this->a->id))->toBe(3)->and(ordReserved($this->b->id))->toBe(2);
    $movementsBefore = StockMovement::query()->count();

    $cancelled = app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest, 'গ্রাহক অর্ডার বাতিল করতে চেয়েছেন', userId: null);

    expect($cancelled->status)->toBe(OrderStatus::Cancelled)
        ->and($cancelled->cancel_reason)->toBe(CancellationReason::CustomerRequest)
        ->and($cancelled->cancel_note)->toBe('গ্রাহক অর্ডার বাতিল করতে চেয়েছেন')
        ->and($cancelled->cancelled_at)->not->toBeNull()
        ->and(ordReserved($this->a->id))->toBe(0)
        ->and(ordReserved($this->b->id))->toBe(0)
        // Releasing a reservation writes no movement: on_hand never changed (§7.1).
        ->and(StockMovement::query()->count())->toBe($movementsBefore)
        ->and(StockLevel::query()->where('variant_id', $this->a->id)->value('on_hand'))->toBe(10);

    $event = OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'cancelled')->sole();
    expect($event->is_customer_visible)->toBeTrue()
        ->and($event->meta)->toMatchArray(['reason' => 'customer_request']);
});

it('cancels the open COD payment and leaves the order unpaid', function (): void {
    $order = OrderFixtures::place([$this->a->id => 1]);

    app(CancelOrder::class)->handle($order, CancellationReason::FakeOrder);

    expect($order->payments()->sole()->status)->toBe(PaymentStatus::Cancelled)
        ->and($order->refresh()->payment_status)->toBe(OrderPaymentStatus::Unpaid);
});

it('emits OrderCancelled with what coupon and risk listeners need', function (): void {
    Event::fake([OrderCancelled::class]);
    $order = OrderFixtures::place([$this->a->id => 1], overrides: ['coupon_code' => 'EID10']);

    app(CancelOrder::class)->handle($order, CancellationReason::CustomerUnreachable, userId: null);

    Event::assertDispatched(OrderCancelled::class, fn (OrderCancelled $e): bool => $e->orderId === $order->id
        && $e->customerId === $order->customer_id
        && $e->from === 'pending' && $e->to === 'cancelled'
        && $e->reason === 'customer_unreachable'
        && $e->couponCode === 'EID10'
        && $e->paidTotal === '0.00');
});

it('cancels from every pre-shipment status', function (OrderStatus $status): void {
    $order = OrderFixtures::place([$this->a->id => 2]);
    $path = match ($status) {
        OrderStatus::Pending => [],
        OrderStatus::OnHold => [OrderStatus::OnHold],
        OrderStatus::Confirmed => [OrderStatus::Confirmed],
        OrderStatus::Processing => [OrderStatus::Confirmed, OrderStatus::Processing],
        OrderStatus::ReadyToShip => [OrderStatus::Confirmed, OrderStatus::ReadyToShip],
        default => throw new LogicException,
    };
    foreach ($path as $step) {
        app(TransitionOrder::class)->handle($order, $step);
    }

    app(CancelOrder::class)->handle($order, CancellationReason::OutOfStock);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)->and(ordReserved($this->a->id))->toBe(0);
})->with([OrderStatus::Pending, OrderStatus::OnHold, OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip]);

it('refuses to cancel once shipped, touching nothing', function (OrderStatus $status): void {
    $order = Order::factory()->status($status)->create();

    expect(fn () => app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest))->toThrow(OrderNotCancellable::class);
    expect($order->refresh()->status)->toBe($status);
})->with([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Completed, OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::PartiallyReturned]);

it('requires a note when the reason is "other"', function (): void {
    $order = OrderFixtures::place([$this->a->id => 1]);

    expect(fn () => app(CancelOrder::class)->handle($order, CancellationReason::Other, '   '))->toThrow(InvalidArgumentException::class);
    expect($order->refresh()->status)->toBe(OrderStatus::Pending)->and(ordReserved($this->a->id))->toBe(1);
});

it('releases nothing twice: a second cancel is refused and the reservation stays consistent', function (): void {
    $order = OrderFixtures::place([$this->a->id => 4]);
    OrderFixtures::place([$this->a->id => 1]);

    app(CancelOrder::class)->handle($order, CancellationReason::DuplicateOrder);

    expect(fn () => app(CancelOrder::class)->handle($order, CancellationReason::DuplicateOrder))->toThrow(OrderNotCancellable::class);
    // §7.5: reserved = Σ open-order quantity.
    expect(ordReserved($this->a->id))->toBe(1);
});

it('rolls everything back when the cancel fails part-way', function (): void {
    $order = OrderFixtures::place([$this->a->id => 2]);
    // Corrupt the level so the release is a "double release" and throws inside the transaction.
    DB::table('stock_levels')->where('variant_id', $this->a->id)->update(['reserved' => 1]);

    expect(fn () => app(CancelOrder::class)->handle($order, CancellationReason::CustomerRequest))->toThrow(LogicException::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->payments()->sole()->status)->toBe(PaymentStatus::Pending)
        ->and(OrderEvent::query()->where('order_id', $order->id)->where('to_status', 'cancelled')->exists())->toBeFalse();
});

it('skips lines whose variant no longer exists', function (): void {
    $order = OrderFixtures::place([$this->a->id => 1]);
    $order->items()->first()?->forceFill(['variant_id' => null])->saveQuietly();
    DB::table('stock_levels')->where('variant_id', $this->a->id)->update(['reserved' => 0]);

    app(CancelOrder::class)->handle($order, CancellationReason::OutOfStock);

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});
