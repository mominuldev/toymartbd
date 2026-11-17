<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Ai\Advisor\Models\AiBriefAction;
use App\Models\Enums\Permission;
use App\Models\User;

/**
 * Analytics › Growth advisor (Figma 537:82561): the weekly brief, its suggested actions and
 * Ask AI. Everything is reports.view — the advisor only reads report figures and suggests.
 *
 *   view ..... reports.view   read the brief, regenerate it, ask a question
 *   decide ... reports.view   mark a suggested action done / not useful
 */
final class AiBriefPolicy extends Policy
{
    public function view(User $user): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }

    public function decide(User $user, AiBriefAction $action): bool
    {
        return $this->allows($user, Permission::ReportsView);
    }
}
