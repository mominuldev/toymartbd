<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Stock levels, movements and locations (inventory). The ledger is append-only, so
 * there is no update or delete ability: a change is a new movement, which needs
 * inventory.adjust (opening stock and stock adjustments). Seeing cost or valuation
 * additionally needs catalog.view_cost (ProductPolicy::viewCost).
 */
final class StockPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::InventoryView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::InventoryView);
    }

    public function adjust(User $user): bool
    {
        return $this->allows($user, Permission::InventoryAdjust);
    }
}
