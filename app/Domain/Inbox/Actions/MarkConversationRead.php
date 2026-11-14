<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Models\Conversation;

/** Opening a thread clears its unread count. One update, no transaction. */
final readonly class MarkConversationRead
{
    public function handle(Conversation $conversation): Conversation
    {
        if ($conversation->unread_count !== 0) {
            $conversation->unread_count = 0;
            $conversation->save();
        }

        return $conversation;
    }
}
