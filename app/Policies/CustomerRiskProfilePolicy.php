<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Risk\Models\CustomerRiskProfile;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Phone risk profiles: read with risk.view; put a phone on a list with risk.manage_lists; edit
 * the scoring rules with risk.manage_rules. Nobody edits the counts or the score — they are
 * our projection of the orders.
 */
final class CustomerRiskProfilePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RiskView);
    }

    public function view(User $user, CustomerRiskProfile $profile): bool
    {
        return $this->allows($user, Permission::RiskView);
    }

    public function setStatus(User $user): bool
    {
        return $this->allows($user, Permission::RiskManageLists);
    }

    public function manageRules(User $user): bool
    {
        return $this->allows($user, Permission::RiskManageRules);
    }
}
