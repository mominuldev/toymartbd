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
 *
 * P5-INV: approving or rejecting an above-threshold adjustment, and acknowledging an
 * inventory alert (drift never closes without a person), need
 * inventory.approve_adjustments; moving stock between locations needs
 * inventory.transfer. Also the policy for StockTransfer(Item) and InventoryAlert.
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

    public function approve(User $user): bool
    {
        return $this->allows($user, Permission::InventoryApproveAdjustments);
    }

    public function transfer(User $user): bool
    {
        return $this->allows($user, Permission::InventoryTransfer);
    }

    public function acknowledge(User $user): bool
    {
        return $this->allows($user, Permission::InventoryApproveAdjustments);
    }
}
