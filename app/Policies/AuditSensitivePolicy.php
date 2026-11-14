<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * The activity log (Figma B · 39) over `audit_sensitive`. Read-only: rows are insert-only and
 * nobody, the owner included, can edit or delete one.
 *
 *   viewAny ... audit.view                    (super_admin, manager, accountant)
 *   export .... audit.view + reports.export   (the same roles by default; data leaving the admin
 *               follows every other export's reports.export rule)
 */
final class AuditSensitivePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::AuditView);
    }

    public function export(User $user): bool
    {
        return $this->viewAny($user) && $this->allows($user, Permission::ReportsExport);
    }
}
