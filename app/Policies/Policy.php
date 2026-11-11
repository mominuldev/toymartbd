<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Base for every model Policy (CLAUDE.md rule 21, P3-API-06).
 *
 * A policy method answers one question — "does this staff member hold the
 * Permission this action needs?" — and adds any per-record condition on top.
 * super_admin never reaches a policy method: Gate::before in
 * App\Providers\AuthServiceProvider passes it first.
 *
 * Endpoints without a model use the `can:` route middleware with a Permission
 * value instead (spatie registers every permission with the Gate):
 *     ->middleware('can:'.Permission::ReportsView->value)
 */
abstract class Policy
{
    /**
     * An unseeded permission name is a plain "no" rather than an exception.
     * Deactivated staff are refused earlier, by EnsureActiveStaffSession.
     */
    protected function allows(User $user, Permission $permission): bool
    {
        return $user->checkPermissionTo($permission->value);
    }
}
