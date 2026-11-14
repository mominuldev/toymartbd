<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Reporting\Models\ReportSchedule;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Scheduled report emails (P8). A schedule sends report data out of the admin by email,
 * so it needs the export permission, not just the view one:
 *
 *   viewAny / view / create / update / delete /
 *   resend (P9: a failed run, again) ............ reports.view + reports.export
 *                                                 (super_admin, manager, accountant)
 *
 * The FormRequest additionally refuses a report type the actor could not open themselves
 * (no scheduling a P&L without finance.view) and any recipient who may not receive it.
 */
final class ReportSchedulePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView) && $this->allows($user, Permission::ReportsExport);
    }

    public function view(User $user, ReportSchedule $schedule): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ReportSchedule $schedule): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return $this->viewAny($user);
    }

    /** Resend a failed run of this schedule for the same period (P9). */
    public function resend(User $user, ReportSchedule $schedule): bool
    {
        return $this->viewAny($user);
    }
}
