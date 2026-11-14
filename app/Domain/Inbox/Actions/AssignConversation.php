<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Assigns (or unassigns, `$assignee = null`) a conversation, with a system line in the thread. */
final readonly class AssignConversation
{
    public function handle(Conversation $conversation, ?User $assignee, User $by): Conversation
    {
        return DB::transaction(function () use ($conversation, $assignee, $by): Conversation {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($locked->assigned_to === $assignee?->id) {
                return $locked;
            }

            $locked->assigned_to = $assignee?->id;
            $locked->save();

            ConversationMessage::query()->create([
                'conversation_id' => $locked->id,
                'channel' => $locked->channel,
                'direction' => MessageDirection::Out,
                'kind' => MessageKind::System,
                'system_event' => $assignee === null ? SystemEvent::Unassigned : SystemEvent::Assigned,
                'sender_user_id' => $by->id,
                'meta' => ['assignee' => $assignee?->name, 'by' => $by->name],
            ]);

            return $locked;
        });
    }
}
