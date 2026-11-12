<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Ordering\Actions\Returns\ApproveRefund;
use App\Domain\Payment\Models\Refund;
use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Refunds and refund items (§3.5, §4.7). Registered for Refund and RefundItem (rule 21).
 *
 * Approval threshold (`returns.approval_threshold`, a PLACEHOLDER until the owner sets
 * it): a refund above it needs refunds.approve (manager, accountant, owner); at or below
 * it, refunds.create (order operator) is enough.
 */
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

    /** Approve or reject — reject follows the same rule, so nobody can veto what they could not approve. */
    public function approve(User $user, Model $refund): bool
    {
        if ($this->allows($user, Permission::RefundsApprove)) {
            return true;
        }

        return $refund instanceof Refund
            && ! ApproveRefund::needsManager($refund)
            && $this->allows($user, Permission::RefundsCreate);
    }

    /** Record or trigger the payout of an approved refund. */
    public function pay(User $user, Model $refund): bool
    {
        return $this->allows($user, Permission::RefundsCreate) || $this->allows($user, Permission::RefundsApprove);
    }

    /** Whether this user may approve above the threshold (passed to ApproveRefund). */
    public function approveAboveThreshold(User $user): bool
    {
        return $this->allows($user, Permission::RefundsApprove);
    }
}
