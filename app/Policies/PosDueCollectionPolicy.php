<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Pos\Models\PosDueCollection;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * A due collection (owner decision 2026-11-15). Taking one is `operate` on one's own open
 * drawer (PosSessionPolicy, `pos.use`); reading one: the cashier who took it, or `pos.manage`.
 * Collections are insert-only.
 */
final class PosDueCollectionPolicy extends Policy
{
    public function view(User $user, PosDueCollection $collection): bool
    {
        return $this->allows($user, Permission::PosManage)
            || ($this->allows($user, Permission::PosUse) && $collection->cashier_id === $user->id);
    }
}
