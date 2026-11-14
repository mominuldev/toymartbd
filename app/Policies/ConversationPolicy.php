<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Unified inbox (P10, Figma B · 22) — conversations and their messages (rule 21).
 * inbox.view reads (and clears the unread count); inbox.reply sends, notes, assigns,
 * resolves and reopens. The contact's full phone additionally needs customers.view_pii
 * (rule 20), checked by the resource.
 */
final class ConversationPolicy extends Policy
{
    public function viewAny(User $user): bool
    {
        return $this->allows($user, Permission::InboxView);
    }

    public function view(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxView);
    }

    public function markRead(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxView);
    }

    public function reply(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxReply);
    }

    public function note(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxReply);
    }

    public function assign(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxReply);
    }

    public function resolve(User $user, Model $conversation): bool
    {
        return $this->allows($user, Permission::InboxReply);
    }
}
