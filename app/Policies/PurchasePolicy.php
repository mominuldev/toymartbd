<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Purchasing\Models\Purchase;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Purchase orders. Never deleted: a PO that will not happen is cancelled (needs
 * purchasing.create, like raising it). Receiving stock needs purchasing.receive. PO
 * money is cost, so amounts are shown only with catalog.view_cost (`viewCost`).
 */
final class PurchasePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PurchasingView);
    }

    public function view(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PurchasingCreate);
    }

    public function update(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingCreate);
    }

    public function place(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingCreate);
    }

    public function cancel(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingCreate);
    }

    /** Close a partly received PO short (the supplier will not send the rest). */
    public function close(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingCreate);
    }

    public function receive(User $user, Purchase $purchase): bool
    {
        return $this->allows($user, Permission::PurchasingReceive);
    }

    public function viewCost(User $user): bool
    {
        return $this->allows($user, Permission::CatalogViewCost);
    }
}
