<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers and their saved addresses (§3.3). Registered for Customer and Address
 * (rule 21). `viewPii` gates the full phone and address (rule 20).
 */
final class CustomerPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CustomersView);
    }

    public function view(User $user, Model $customer): bool
    {
        return $this->allows($user, Permission::CustomersView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::CustomersCreate);
    }

    public function update(User $user, Model $customer): bool
    {
        return $this->allows($user, Permission::CustomersUpdate);
    }

    public function viewPii(User $user): bool
    {
        return $this->allows($user, Permission::CustomersViewPii);
    }
}
