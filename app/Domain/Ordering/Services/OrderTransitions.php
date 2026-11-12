<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

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
use App\Domain\Ordering\Events\OrderPlaced;
use App\Domain\Ordering\Events\OrderProcessingStarted;
use App\Domain\Ordering\Events\OrderPutOnHold;
use App\Domain\Ordering\Events\OrderReadyToShip;
use App\Domain\Ordering\Events\OrderReleasedFromHold;
use App\Domain\Ordering\Events\OrderReturned;
use App\Domain\Ordering\Events\OrderShipped;
use App\Domain\Ordering\Events\OrderStatusChanged;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;
use App\Domain\Ordering\Exceptions\OrderViolation;
use App\Domain\Ordering\Models\Order;
use App\Domain\Ordering\Models\OrderEvent;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of an order's three status columns (P4-ORD-03). Owns no transaction
 * (rule 3) and refuses to run outside the caller's. Each call:
 *
 *   1. re-reads the order `FOR UPDATE` (a second click waits, then sees the new state);
 *   2. asserts the move against the state machine — anything unlisted is a 409;
 *   3. stamps the timeline column and any extra attributes, and saves;
 *   4. appends one `order_events` row;
 *   5. dispatches the transition's domain event (held until commit).
 *
 * Side effects that belong to a transition (releasing stock on cancel, consuming it on
 * dispatch, ledger rows on delivery) are the calling Action's job, in the same transaction.
 */
final readonly class OrderTransitions
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns written with the move (e.g. cancel_reason)
     * @param  array<string, mixed>  $meta  stored on the order_events row — never a full phone or address
     *
     * @throws InvalidOrderTransition
     */
    public function transition(
        Order $order,
        OrderStatus $to,
        ?int $userId = null,
        array $attributes = [],
        array $meta = [],
        ?string $description = null,
    ): Order {
        $locked = $this->lock($order);
        $from = $locked->status;
        OrderStateMachine::assertStatus($from, $to);

        $locked->forceFill($attributes);
        $locked->status = $to;
        if (($column = $to->timestampColumn()) !== null) {
            $locked->setAttribute($column, now());
        }
        $locked->saveTransition();

        $this->record($locked, OrderEventType::StatusChanged, 'status', $from->value, $to->value, $userId, $to->isCustomerVisible(), $meta, $description);
        event(self::eventFor($locked, $from, $to, $userId));

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $attributes  e.g. paid_total / due_total, written with the move
     *
     * @throws InvalidOrderTransition
     */
    public function transitionPayment(Order $order, OrderPaymentStatus $to, ?int $userId = null, array $attributes = []): Order
    {
        $locked = $this->lock($order);
        $from = $locked->payment_status;
        OrderStateMachine::assertPayment($from, $to);

        $locked->forceFill($attributes);
        $locked->payment_status = $to;
        $locked->saveTransition();

        $this->record($locked, OrderEventType::PaymentStatusChanged, 'payment_status', $from->value, $to->value, $userId, false);
        OrderPaymentStatusChanged::dispatch($locked->id, $locked->customer_id, $from->value, $to->value);

        return $locked;
    }

    /** @throws InvalidOrderTransition */
    public function transitionFulfillment(Order $order, FulfillmentStatus $to, ?int $userId = null): Order
    {
        $locked = $this->lock($order);
        $from = $locked->fulfillment_status;
        OrderStateMachine::assertFulfillment($from, $to);

        $locked->fulfillment_status = $to;
        $locked->saveTransition();

        $this->record($locked, OrderEventType::FulfillmentStatusChanged, 'fulfillment_status', $from->value, $to->value, $userId, false);
        OrderFulfillmentStatusChanged::dispatch($locked->id, $from->value, $to->value);

        return $locked;
    }

    /** The first timeline row of a new order, plus OrderPlaced (PlaceOrder, manual orders). */
    public function placed(Order $order, ?int $userId = null): void
    {
        self::guard();

        $this->record($order, OrderEventType::Placed, 'status', null, $order->status->value, $userId, true);
        OrderPlaced::dispatch($order->id, $order->customer_id, $order->coupon_code);
    }

    /** The domain event a move into `$to` emits. Every target status has exactly one. */
    public static function eventFor(Order $order, OrderStatus $from, OrderStatus $to, ?int $userId = null): OrderStatusChanged
    {
        $args = [$order->id, $order->customer_id, $from->value, $to->value, $userId];

        return match ($to) {
            OrderStatus::Pending => new OrderReleasedFromHold(...$args),
            OrderStatus::OnHold => new OrderPutOnHold(...$args),
            OrderStatus::Confirmed => new OrderConfirmed(...$args),
            OrderStatus::Processing => new OrderProcessingStarted(...$args),
            OrderStatus::ReadyToShip => new OrderReadyToShip(...$args),
            OrderStatus::Shipped => new OrderShipped(...$args),
            OrderStatus::Delivered => new OrderDelivered(...$args),
            OrderStatus::Completed => new OrderCompleted(...$args),
            OrderStatus::Returned => new OrderReturned(...$args),
            OrderStatus::PartiallyReturned => new OrderPartiallyReturned(...$args),
            OrderStatus::Cancelled => new OrderCancelled(
                $order->id,
                $order->customer_id,
                $from->value,
                $order->cancel_reason->value ?? 'other',
                $order->cancel_note,
                $order->coupon_code,
                (string) $order->paid_total,
                $userId,
            ),
        };
    }

    private function lock(Order $order): Order
    {
        self::guard();

        /** @var Order */
        return Order::query()->lockForUpdate()->findOrFail($order->id);
    }

    /** @param  array<string, mixed>  $meta */
    private function record(
        Order $order,
        OrderEventType $type,
        string $field,
        ?string $from,
        ?string $to,
        ?int $userId,
        bool $customerVisible,
        array $meta = [],
        ?string $description = null,
    ): void {
        OrderEvent::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'field' => $field,
            'from_status' => $from,
            'to_status' => $to,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
            'user_id' => $userId,
            'is_customer_visible' => $customerVisible,
        ]);
    }

    private static function guard(): void
    {
        if (DB::transactionLevel() === 0) {
            throw OrderViolation::outsideTransaction();
        }
    }
}
