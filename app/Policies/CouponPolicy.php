<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Coupons and their usages (P4-CRT-04). Viewing needs marketing.view; creating,
 * editing and deactivating need marketing.manage. There is no hard delete.
 */
final class CouponPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MarketingView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::MarketingView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }

    public function update(User $user): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }

    /** Deactivate — coupons are never deleted. */
    public function delete(User $user): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }
}
