<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers, their saved addresses, staff notes and children (§3.3). Registered for Customer,
 * Address, CustomerNote and CustomerChild (rule 21). `viewPii` gates the full phone and address (rule 20).
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

    /** Append a staff note to a customer (customer_notes) — customers.update. */
    public function addNote(User $user, Model $customer): bool
    {
        return $this->allows($user, Permission::CustomersUpdate);
    }

    /**
     * Add, edit or remove a customer's children (P8 batch 3) — children's data, so
     * customers.update AND customers.view_pii (rule 20).
     */
    public function manageChildren(User $user, Model $customer): bool
    {
        return $this->allows($user, Permission::CustomersUpdate) && $this->allows($user, Permission::CustomersViewPii);
    }

    public function viewPii(User $user): bool
    {
        return $this->allows($user, Permission::CustomersViewPii);
    }
}
