<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Sales analytics (P8). Read-only; the report class is the policy subject.
 *
 *   view ........ reports.view                       (super_admin, manager, accountant, viewer)
 *   viewProfit .. finance.view + catalog.view_cost   (cost and profit columns — the
 *                 profitability report's rule; otherwise those fields are null)
 *   export ...... reports.view + reports.export      (super_admin, manager, accountant)
 */
final class SalesAnalyticsPolicy extends Policy
{
    public function view(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function viewProfit(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView) && $this->allows($user, Permission::CatalogViewCost);
    }

    public function export(User $user): bool
    {
        return $this->view($user) && $this->allows($user, Permission::ReportsExport);
    }
}
