<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Enums;

/** `order_events.type` — one row per thing that happened to an order. */
enum OrderEventType: string
{
    case Placed = 'placed';
    case StatusChanged = 'status_changed';
    case PaymentStatusChanged = 'payment_status_changed';
    case FulfillmentStatusChanged = 'fulfillment_status_changed';
    case Note = 'note';
}
