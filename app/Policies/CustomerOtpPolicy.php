<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Sign-in codes (P4-STO-11). No staff role may read or change them — they hold only hashes,
 * and nothing in the admin needs them. super_admin passes Gate::before as everywhere, but no
 * admin endpoint exposes this model.
 */
final class CustomerOtpPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user): bool
    {
        return false;
    }
}
