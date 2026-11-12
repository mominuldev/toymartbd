<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Notification templates (P4-NOT-03) live under Settings → Automations: reading needs
 * settings.view, editing settings.update. Templates are never created or deleted through
 * the API — the set is the code catalog; an admin edits copy and switches one off.
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
}
