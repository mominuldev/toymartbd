<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Inbox;

use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Queries\ConversationsNeedingAttention;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Admin\Inbox\InboxUnreadCountResource;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;

/**
 * The Inbox nav badge (owner decision 2026-11-15). Read-only, inbox.view.
 */
final class InboxBadgeController extends ApiController
{
    /**
     * `GET /inbox/unread-count` — open conversations that need attention: unread inbound
     * messages or waiting on a staff reply (`needs_human`), each counted once. One COUNT.
     */
    #[OpenApiResponse(403, 'Missing inbox.view (`forbidden`)')]
    public function show(ConversationsNeedingAttention $attention): InboxUnreadCountResource
    {
        $this->authorize('viewAny', Conversation::class);

        return new InboxUnreadCountResource(['count' => $attention->count()]);
    }
}
