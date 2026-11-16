<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\CapitalHolder;
use App\Models\Enums\Permission;
use App\Models\User;

/** Capital holders (P10): read with finance.view; add and change with finance.manage_capital. Never deleted. */
final class CapitalHolderPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, CapitalHolder $holder): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManageCapital);
    }

    public function update(User $user, CapitalHolder $holder): bool
    {
        return $this->allows($user, Permission::FinanceManageCapital);
    }
}
