<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Returns (RMA) and their lines (§4.7, rule 21). Registered for OrderReturn and OrderReturnItem. */
final class OrderReturnPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ReturnsView);
    }

    public function view(User $user, Model $return): bool
    {
        return $this->allows($user, Permission::ReturnsView);
    }

    /** Open a return, mark it received, inspect it, reject it. */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::ReturnsManage);
    }

    public function update(User $user, Model $return): bool
    {
        return $this->allows($user, Permission::ReturnsManage);
    }
}
