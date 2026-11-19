<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Payment\Models\PaymentProvider;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Integrations › Payments (owner request 2026-11-19): the online gateways' keys, mode and
 * on/off switch. One power — settings.manage_integrations (rule 20; the permission that holds
 * courier, gateway, tracking and messaging credentials). Gateways are reference rows: no
 * create or delete, and `verified` is never set here (owner CLI only).
 */
final class PaymentProviderPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsManageIntegrations);
    }

    public function view(User $user, PaymentProvider $gateway): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ?PaymentProvider $gateway = null): bool
    {
        return $this->allows($user, Permission::SettingsManageIntegrations);
    }

    public function checkConnection(User $user, ?PaymentProvider $gateway = null): bool
    {
        return $this->allows($user, Permission::SettingsManageIntegrations);
    }
}
