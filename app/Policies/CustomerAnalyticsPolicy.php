<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Customer analytics (P8: LTV, cohorts, repeat rate, segments). Read-only. It shows
 * customer names (phones always masked) next to money, so it needs both:
 *
 *   view ..... reports.view + customers.view
 */
final class CustomerAnalyticsPolicy extends Policy
{
    public function view(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView) && $this->allows($user, Permission::CustomersView);
    }
}
