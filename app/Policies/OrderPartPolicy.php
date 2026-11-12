<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Order items and order events are parts of the order aggregate, read through the order
 * and written only by Ordering Actions. Registered for OrderItem and OrderEvent (rule 21).
 * No update or delete: snapshots and the timeline are immutable.
 */
final class OrderPartPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function view(User $user, Model $part): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }
}
