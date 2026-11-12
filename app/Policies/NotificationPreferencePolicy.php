<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * A customer's notification opt-ins (P4-NOT-03), edited by staff on the customer's
 * behalf (e.g. "stop texting me" on a call): customers.view to read, customers.update
 * to change.
 */
final class NotificationPreferencePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::CustomersView);
    }

    public function update(User $user): bool
    {
        return $this->allows($user, Permission::CustomersUpdate);
    }
}
