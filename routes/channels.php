<?php

declare(strict_types=1);

use App\Domain\Inbox\Events\InboxConversationChanged;
use App\Domain\Inbox\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels (Laravel Reverb) — docs/api/inbox.md › Live updates
|--------------------------------------------------------------------------
|
| Authorised by POST /api/v1/admin/broadcasting/auth inside the admin's Sanctum cookie session
| (auth, active staff session, two-factor enrolment). Every channel defers to a Policy.
| Payloads are signals (ids), never personal data: the admin refetches through the API.
|
*/

// inbox.view (ConversationPolicy::viewAny) — the same check as GET /inbox/conversations.
Broadcast::channel(InboxConversationChanged::CHANNEL, static fn (User $user): bool => $user->can('viewAny', Conversation::class));
