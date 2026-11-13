<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Courier performance report (P6). Read-only. It shows delivery charges and COD amounts per
 * courier, so it needs both the reports permission and shipment visibility:
 *
 *   view ..... reports.view + shipments.view   (super_admin, manager, accountant, viewer)
 *   export ... the above + reports.export      (super_admin, manager, accountant)
 */
final class CourierPerformanceReportPolicy extends Policy
{
    public function view(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView) && $this->allows($user, Permission::ShipmentsView);
    }

    public function export(User $user): bool
    {
        return $this->view($user) && $this->allows($user, Permission::ReportsExport);
    }
}
