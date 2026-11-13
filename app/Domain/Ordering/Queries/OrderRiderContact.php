<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Queries;

use App\Domain\Fulfilment\Models\Shipment;
use App\Support\PhoneNumber;

/**
 * The delivery rider's phone for an order, for the "out for delivery" message (owner decision
 * 2026-11-13: the RIDER's number, never our hotline). Ordering may read Fulfilment (deptrac)
 * and Notification may read Ordering, so this is the seam Notification uses — Notification
 * never imports Fulfilment.
 *
 * The number comes from the order's current shipment (the newest that is not failed or
 * cancelled): staff record it when the rider calls, since neither courier's API returns it.
 * Null when nobody has recorded one — the message then uses its no-rider variant.
 */
final class OrderRiderContact
{
    public function phoneFor(int $orderId): ?PhoneNumber
    {
        $phone = Shipment::query()
            ->where('order_id', $orderId)
            ->open()
            ->orderByDesc('id')
            ->value('rider_phone');

        return is_string($phone) ? PhoneNumber::tryParse($phone) : null;
    }
}
