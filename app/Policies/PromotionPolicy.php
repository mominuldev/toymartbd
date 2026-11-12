<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/** Promotions (P4-CRT-02). No admin endpoint yet; the same permissions as coupons. */
final class PromotionPolicy extends Policy
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

    public function delete(User $user): bool
    {
        return $this->allows($user, Permission::MarketingManage);
    }
}
