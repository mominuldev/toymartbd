<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/** Refunds and refund items (§3.5). Registered for Refund and RefundItem (rule 21). */
final class RefundPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RefundsView);
    }

    public function view(User $user, Model $refund): bool
    {
        return $this->allows($user, Permission::RefundsView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::RefundsCreate);
    }

    public function approve(User $user, Model $refund): bool
    {
        return $this->allows($user, Permission::RefundsApprove);
    }
}
