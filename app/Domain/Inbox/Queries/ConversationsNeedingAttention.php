<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Inbox nav badge (owner decision 2026-11-15): how many conversations need attention.
 *
 * A conversation needs attention when it is OPEN and either
 *   - has unread inbound messages (`unread_count > 0`; cleared when staff open the thread), or
 *   - is waiting on a staff reply (`needs_human`; set by every inbound message, cleared by a
 *     staff reply or by resolving).
 * Each conversation counts once, whichever of the two applies. A resolved conversation never
 * counts; a new inbound message reopens it and so brings it back. Shared by every staff member
 * with inbox.view — it is not per-assignee.
 */
final readonly class ConversationsNeedingAttention
{
    public function count(): int
    {
        return $this->query()->count();
    }

    /** @return Builder<Conversation> */
    public function query(): Builder
    {
        return Conversation::query()
            ->where('status', ConversationStatus::Open->value)
            ->where(static function (Builder $q): void {
                $q->where('unread_count', '>', 0)->orWhere('needs_human', true);
            });
    }
}
