<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Staff management (`users` are staff accounts). Staff are deactivated, never
 * deleted, so there is no `delete` ability.
 */
final class UserPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::StaffView);
    }

    public function view(User $user, User $staff): bool
    {
        return $user->is($staff) || $this->allows($user, Permission::StaffView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::StaffManage);
    }

    public function update(User $user, User $staff): bool
    {
        return $this->allows($user, Permission::StaffManage);
    }
}
