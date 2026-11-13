<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Fulfilment\Models\CourierProvider;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Courier settings & delivery charges (P6). Two separate powers:
 * - the rate card, the free-delivery threshold and which provider is on/default →
 *   shipments.manage_rates (a manager runs prices);
 * - credentials and the webhook secret → settings.manage_integrations (rule 20; the
 *   manager role deliberately lacks it).
 * Either one opens the screen. Providers are rows of reference data: no create or delete.
 */
final class CourierProviderPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::ShipmentsManageRates)
            || $this->allows($user, Permission::SettingsManageIntegrations);
    }

    public function view(User $user, CourierProvider $provider): bool
    {
        return $this->viewAny($user);
    }

    public function updateRates(User $user, ?CourierProvider $provider = null): bool
    {
        return $this->allows($user, Permission::ShipmentsManageRates);
    }

    public function updateCredentials(User $user, CourierProvider $provider): bool
    {
        return $this->allows($user, Permission::SettingsManageIntegrations);
    }
}
