<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolve or reopen. Resolving clears "needs human"; a later customer message reopens the
 * conversation on its own ({@see IngestInboundMessage}). Conversations are never deleted.
 */
final readonly class SetConversationStatus
{
    public function handle(Conversation $conversation, ConversationStatus $status, User $by): Conversation
    {
        return DB::transaction(function () use ($conversation, $status, $by): Conversation {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($locked->status === $status) {
                return $locked;
            }

            $locked->status = $status;
            $locked->resolved_at = $status === ConversationStatus::Resolved ? now() : null;
            if ($status === ConversationStatus::Resolved) {
                $locked->needs_human = false;
            }
            $locked->save();

            ConversationMessage::query()->create([
                'conversation_id' => $locked->id,
                'channel' => $locked->channel,
                'direction' => MessageDirection::Out,
                'kind' => MessageKind::System,
                'system_event' => $status === ConversationStatus::Resolved ? SystemEvent::Resolved : SystemEvent::Reopened,
                'sender_user_id' => $by->id,
                'meta' => ['by' => $by->name],
            ]);

            return $locked;
        });
    }
}
