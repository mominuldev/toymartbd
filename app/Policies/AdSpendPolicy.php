<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Marketing\Models\AdSpend;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Ad spend and the Marketing ROI report it feeds (P10, Analytics › Marketing ROI).
 *
 *   viewAny ............. reports.view      (the ROI report and the spend list)
 *   create/update/delete  marketing.manage  (typing ad spend in)
 */
final class AdSpendPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }

    public function update(User $user, AdSpend $spend): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }

    public function delete(User $user, AdSpend $spend): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }
}
