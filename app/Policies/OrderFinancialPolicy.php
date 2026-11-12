<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Ordering\Models\OrderFinancial;
use App\Models\Enums\Permission;
use App\Models\User;

/** Per-order profit (cost and margin) is finance data. Written only by the Finance projection. */
final class OrderFinancialPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function view(User $user, OrderFinancial $financial): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }
}
