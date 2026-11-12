<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Carts and cart lines. Customers reach their cart through the storefront token, not
 * a policy; staff may only read carts (abandoned-cart views, Phase 5) with
 * orders.view. Staff never edit a customer's cart.
 */
final class CartPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }
}
