<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Contracts;

use App\Domain\Fulfilment\DTOs\ShippableOrder;

/**
 * Fulfilment's read seam onto orders (SYSTEM-ARCHITECTURE §4: cross-domain reads go through
 * the owning domain's service). Implemented by Ordering
 * ({@see \App\Domain\Ordering\Queries\EloquentShippableOrders}) and bound in
 * FulfilmentServiceProvider. Writes to the order never go through here — Fulfilment emits
 * events and Ordering's listeners act on them (rule 2).
 */
interface ShippableOrders
{
    public function find(int $orderId): ?ShippableOrder;

    /**
     * Lock the order row `FOR UPDATE` inside the caller's transaction and return its
     * snapshot, so "assert shippable + insert shipment" cannot race another request.
     */
    public function lockForShipment(int $orderId): ?ShippableOrder;
}
