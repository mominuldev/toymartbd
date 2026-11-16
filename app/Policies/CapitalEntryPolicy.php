<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Finance\Models\CapitalEntry;
use App\Models\Enums\Permission;
use App\Models\User;

/** Owner capital entries (P10): read with finance.view; record and reverse with finance.manage_capital. Insert-only. */
final class CapitalEntryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, CapitalEntry $entry): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::FinanceManageCapital);
    }

    public function reverse(User $user, CapitalEntry $entry): bool
    {
        return $this->allows($user, Permission::FinanceManageCapital);
    }
}
