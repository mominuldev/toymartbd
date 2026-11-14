<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Pos\Models\PosSession;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * POS drawer sessions (Figma B · 33). `pos.use` opens a drawer and works it — sells, quotes,
 * holds, prints — but only one's OWN drawer: a cashier never rings sales into a colleague's
 * till. Closing: one's own with `pos.use`, anyone's with `pos.manage` (a manager counting a
 * till the cashier left open). Reading a session: own, or `pos.manage`.
 */
final class PosSessionPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::PosUse);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, Permission::PosUse);
    }

    public function view(User $user, PosSession $session): bool
    {
        return $this->allows($user, Permission::PosManage) || $this->operate($user, $session);
    }

    /** Sell, quote, browse the grid, hold and resume carts on this drawer. */
    public function operate(User $user, PosSession $session): bool
    {
        return $this->allows($user, Permission::PosUse) && $session->opened_by === $user->id;
    }

    public function close(User $user, PosSession $session): bool
    {
        return $this->allows($user, Permission::PosManage) || $this->operate($user, $session);
    }
}
