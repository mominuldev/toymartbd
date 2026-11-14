<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Models\User;

/**
 * An internal note in the thread. Staff only: it has no delivery status, is never handed to
 * a driver (no job is queued), and does not become the list preview the way a message does.
 * One insert — no transaction needed.
 */
final readonly class AddInternalNote
{
    public function handle(Conversation $conversation, User $by, string $body): ConversationMessage
    {
        return ConversationMessage::query()->create([
            'conversation_id' => $conversation->id,
            'channel' => $conversation->channel,
            'direction' => MessageDirection::Out,
            'kind' => MessageKind::Note,
            'body' => $body,
            'sender_user_id' => $by->id,
        ]);
    }
}
