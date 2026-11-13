<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * The admin dashboard (P8) has no model; DashboardReport is the policy subject.
 *
 *   view .......... dashboard.view (every launch role holds it)
 *   export ........ view + reports.export (owner decision 2026-11-13, like the sales export)
 *
 * What each block shows is decided per block by the viewer's other permissions
 * (DashboardController::scope()); this gate only opens the screen.
 */
final class DashboardPolicy extends Policy
{
    public function view(User $user): bool
    {
        return $this->allows($user, Permission::DashboardView);
    }

    public function export(User $user): bool
    {
        return $this->view($user) && $this->allows($user, Permission::ReportsExport);
    }
}
