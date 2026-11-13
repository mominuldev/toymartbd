<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Fulfilment\Models\Shipment;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Shipments & courier booking (P6). Booking moves stock and money (the order ships, a
 * courier charge is written), so requesting and retrying need shipments.book on top of
 * orders.update — the same pair manual fulfilment needs. Cancelling a not-yet-booked
 * shipment needs shipments.cancel. Shipments are never deleted.
 */
final class ShipmentPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ShipmentsView);
    }

    public function view(User $user, Shipment $shipment): bool
    {
        return $this->viewAny($user);
    }

    /** Request a shipment for one order, or for many from the board. */
    public function request(User $user): bool
    {
        return $this->allows($user, Permission::OrdersUpdate) && $this->allows($user, Permission::ShipmentsBook);
    }

    public function retry(User $user, Shipment $shipment): bool
    {
        return $this->request($user);
    }

    /** Record the delivery rider's name / phone when the rider calls (owner decision 2026-11-13). */
    public function recordRider(User $user, Shipment $shipment): bool
    {
        return $this->allows($user, Permission::ShipmentsBook);
    }

    public function cancel(User $user, Shipment $shipment): bool
    {
        return $this->allows($user, Permission::ShipmentsCancel);
    }
}
