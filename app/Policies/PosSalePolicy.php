<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Pos\Models\PosSale;
use App\Models\Enums\Permission;
use App\Models\User;

/** A counter sale's receipt: the cashier who rang it (`pos.use`), or `pos.manage`. Sales are insert-only. */
final class PosSalePolicy extends Policy
{
    public function view(User $user, PosSale $sale): bool
    {
        return $this->allows($user, Permission::PosManage)
            || ($this->allows($user, Permission::PosUse) && $sale->cashier_id === $user->id);
    }
}
