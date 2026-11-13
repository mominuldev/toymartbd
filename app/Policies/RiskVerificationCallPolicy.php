<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Risk\Models\RiskVerificationCall;
use App\Models\Enums\Permission;
use App\Models\User;

/** The verification queue's call log: read with risk.view, log a call with risk.verify. Insert-only. */
final class RiskVerificationCallPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::RiskView);
    }

    public function view(User $user, RiskVerificationCall $call): bool
    {
        return $this->allows($user, Permission::RiskView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::RiskVerify);
    }
}
