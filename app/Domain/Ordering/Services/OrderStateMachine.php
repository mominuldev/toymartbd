<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Ordering\Enums\FulfillmentStatus;
use App\Domain\Ordering\Enums\OrderPaymentStatus;
use App\Domain\Ordering\Enums\OrderStatus;
use App\Domain\Ordering\Exceptions\InvalidOrderTransition;

/**
 * The guards of the three order state machines (P4-ORD-03). Pure: no I/O. The transition
 * tables themselves live on the enums ({@see OrderStatus::allowedTransitions()} etc.), so
 * the table and its documentation sit side by side. A move to the same state is invalid
 * too — it means two actors raced on one order, and the second must learn so (409).
 */
final class OrderStateMachine
{
    /** @throws InvalidOrderTransition */
    public static function assertStatus(OrderStatus $from, OrderStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::between('status', $from->value, $to->value);
        }
    }

    /** @throws InvalidOrderTransition */
    public static function assertPayment(OrderPaymentStatus $from, OrderPaymentStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::between('payment_status', $from->value, $to->value);
        }
    }

    /** @throws InvalidOrderTransition */
    public static function assertFulfillment(FulfillmentStatus $from, FulfillmentStatus $to): void
    {
        if (! $from->canTransitionTo($to)) {
            throw InvalidOrderTransition::between('fulfillment_status', $from->value, $to->value);
        }
    }
}
