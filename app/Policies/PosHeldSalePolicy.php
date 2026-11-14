<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Pos\Models\PosHeldSale;
use App\Domain\Pos\Models\PosSession;
use App\Models\Enums\Permission;
use App\Models\User;

/** A parked cart belongs to its drawer: resumed by that drawer's cashier (`pos.use`), or `pos.manage`. */
final class PosHeldSalePolicy extends Policy
{
    public function resume(User $user, PosHeldSale $hold): bool
    {
        if ($this->allows($user, Permission::PosManage)) {
            return true;
        }
        $session = PosSession::query()->find($hold->session_id);

        return $session !== null && $this->allows($user, Permission::PosUse) && $session->opened_by === $user->id;
    }
}
