<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Purchasing\Models\Supplier;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Suppliers (purchasing). Archived, never deleted — `delete` is the archive ability.
 * The payable balance is finance data: `viewBalance` needs finance.view.
 */
final class SupplierPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PurchasingView);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, Permission::PurchasingView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PurchasingManageSuppliers);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, Permission::PurchasingManageSuppliers);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $this->allows($user, Permission::PurchasingManageSuppliers);
    }

    public function viewBalance(User $user, ?Supplier $supplier = null): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }
}
