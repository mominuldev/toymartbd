<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Payment\Models\Payment;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Payments are read with their order. They are written only by Payment Actions (driver
 * results, COD collection) — never edited by hand, never deleted.
 */
final class PaymentPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->allows($user, Permission::OrdersView);
    }

    /** Re-query the gateway for an online payment's authoritative status (P4-PAY-03). */
    public function verify(User $user, Payment $payment): bool
    {
        return $this->allows($user, Permission::OrdersUpdate);
    }
}
