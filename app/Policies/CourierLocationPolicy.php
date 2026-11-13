<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Fulfilment\Models\CourierLocation;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Courier location mapping (P6, DATABASE-DESIGN §3.6). Anyone who books couriers can SEE
 * the mapping and what is blocked (shipments.view); changing it, or pulling a fresh tree
 * from the provider, needs shipments.manage_locations — a wrong mapping sends parcels to
 * the wrong zone. Rows are provider data: no create or delete.
 */
final class CourierLocationPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ShipmentsView);
    }

    public function view(User $user, CourierLocation $location): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?CourierLocation $location = null): bool
    {
        return $this->allows($user, Permission::ShipmentsManageLocations);
    }

    public function sync(User $user): bool
    {
        return $this->allows($user, Permission::ShipmentsManageLocations);
    }
}
