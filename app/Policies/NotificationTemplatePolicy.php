<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Notification templates (P4-NOT-03) live under Settings → Automations: reading needs
 * settings.view, editing settings.update. System templates (the code catalog) are never
 * created, archived or deleted through the API — staff edit copy and switch one off.
 * Staff-made marketing / manual templates (P7) are created and archived with
 * settings.update; nothing is ever deleted.
 */
final class NotificationTemplatePolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function update(User $user): bool
    {
        return $this->allows($user, Permission::SettingsUpdate);
    }

    /** A staff-made marketing / manual template (P7 Message templates). */
    public function create(User $user): bool
    {
        return $this->allows($user, Permission::SettingsUpdate);
    }

    /** Custom templates only — the Action refuses a system one with 409. */
    public function archive(User $user): bool
    {
        return $this->allows($user, Permission::SettingsUpdate);
    }
}
