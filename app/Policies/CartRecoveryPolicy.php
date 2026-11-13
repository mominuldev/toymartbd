<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Abandoned-cart recoveries (P7) — read-only in the admin: marketing.view. Rows are
 * written by the detector and the send job only; no admin endpoint changes them.
 */
final class CartRecoveryPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::MarketingView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::MarketingView);
    }
}
