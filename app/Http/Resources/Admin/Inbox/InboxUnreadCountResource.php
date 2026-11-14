<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Inbox;

use App\Domain\Inbox\Queries\ConversationsNeedingAttention;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The Inbox nav badge (Figma SidebarItem CountBadge 185:43): open conversations that need
 * attention — unread, or waiting on a staff reply ({@see ConversationsNeedingAttention}).
 *
 * @property array{count: int} $resource
 */
#[SchemaName('InboxUnreadCount')]
final class InboxUnreadCountResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Open conversations with unread messages or waiting on a staff reply, each counted once.
             *
             * @var int
             */
            'count' => $this->resource['count'],
        ];
    }
}
