<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * The notification log and its dead letters (P4-NOT-01). Viewing needs settings.view;
 * re-queueing a dead letter needs settings.update. Rows are never edited or deleted.
 */
final class NotificationLogPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function retry(User $user): bool
    {
        return $this->allows($user, Permission::SettingsUpdate);
    }
}
