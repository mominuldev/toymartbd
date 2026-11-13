<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Server-side tracking diagnostics (PRD K-03): the event log, one event's dispatch detail
 * and the per-destination health summary. Read-only — rows are written by the dispatcher
 * and never edited or deleted by staff.
 *
 * settings.view, not reports.view: reports.view also reaches the accountant and viewer
 * roles, and a tracking row carries per-visitor identifiers (hashed user data, client ids,
 * order links) plus provider error text — integration plumbing, not a business report.
 * settings.view is held by super_admin and manager only, the same people who configure the
 * integration (settings.manage_integrations).
 */
final class TrackingEventPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }

    public function view(User $user): bool
    {
        return $this->allows($user, Permission::SettingsView);
    }
}
