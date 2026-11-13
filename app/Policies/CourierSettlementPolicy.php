<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Courier COD settlements (§4.5): read with finance.view; import, review a line and confirm
 * with finance.reconcile_cod. Never deleted.
 */
final class CourierSettlementPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, mixed $settlement = null): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceReconcileCod);
    }

    public function reconcile(User $user, mixed $settlement = null): bool
    {
        return $this->allows($user, Permission::FinanceReconcileCod);
    }
}
