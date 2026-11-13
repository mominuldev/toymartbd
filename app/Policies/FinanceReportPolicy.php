<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Finance reports (P5): the P&L and its daily/weekly/monthly/yearly series, product and
 * category profitability, and inventory valuation. Read-only — none of them writes.
 *
 *   P&L, series ............ finance.view
 *   Profitability .......... finance.view + catalog.view_cost (it is built on item cost)
 *   Inventory valuation .... catalog.view_cost + (finance.view or inventory.view)
 *   CSV export ............. the screen's own view ability + finance.export
 *
 * Today that is super_admin, manager and accountant for every report, plus inventory
 * staff for valuation (they already see stock value on the inventory overview).
 */
final class FinanceReportPolicy extends Policy
{
    public function viewPnl(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView);
    }

    public function viewProfitability(User $user): bool
    {
        return $this->allows($user, Permission::FinanceView) && $this->allows($user, Permission::CatalogViewCost);
    }

    public function viewValuation(User $user): bool
    {
        return $this->allows($user, Permission::CatalogViewCost)
            && ($this->allows($user, Permission::FinanceView) || $this->allows($user, Permission::InventoryView));
    }

    public function exportPnl(User $user): bool
    {
        return $this->viewPnl($user) && $this->allows($user, Permission::FinanceExport);
    }

    public function exportProfitability(User $user): bool
    {
        return $this->viewProfitability($user) && $this->allows($user, Permission::FinanceExport);
    }

    public function exportValuation(User $user): bool
    {
        return $this->viewValuation($user) && $this->allows($user, Permission::FinanceExport);
    }
}
