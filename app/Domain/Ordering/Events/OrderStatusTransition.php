<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Events;

/**
 * Marker for every order status transition event (P4-ORD-03). Listen to this interface
 * to react to any transition (customer aggregates, order_financials); listen to one
 * concrete event (OrderCancelled, OrderDelivered…) to react to one. Every implementor
 * extends {@see OrderStatusChanged}.
 *
 * Events carry ids and status strings, never models or enums: the DomainEvents layer may
 * be read by any domain (deptrac), so it cannot depend on Ordering's internals.
 */
interface OrderStatusTransition {}
