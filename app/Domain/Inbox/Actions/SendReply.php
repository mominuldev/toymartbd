<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Exceptions\OutsideServiceWindow;
use App\Domain\Inbox\Jobs\SendInboxMessage;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Services\ReplyWindow;
use App\Domain\Inbox\Support\MessagePreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A staff reply. Records the message as `queued` inside the transaction, then — after
 * commit — queues {@see SendInboxMessage}, which makes the provider call (rule 4: never an
 * HTTP call inside a transaction).
 *
 * A free-form reply needs the conversation's reply state to allow one ({@see ReplyWindow}):
 * `open` (live chat; Meta channels within 24 hours) or `human_agent` (Messenger, 24 h – 7 days,
 * switch on — recorded in `meta.tag`, sent with Meta's HUMAN_AGENT tag). Otherwise this refuses
 * with 409 and queues nothing. The state is checked again under the row lock and once more in
 * the job.
 */
final readonly class SendReply
{
    public function __construct(private ReplyWindow $window) {}

    public function handle(Conversation $conversation, User $by, string $body): ConversationMessage
    {
        $state = $this->window->for($conversation)->state;
        if (! $state->allowsFreeForm()) {
            throw OutsideServiceWindow::make($state);
        }

        $message = DB::transaction(function () use ($conversation, $by, $body): ConversationMessage {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $state = $this->window->for($locked)->state;
            if (! $state->allowsFreeForm()) {
                throw OutsideServiceWindow::make($state);
            }
            $tag = $state->messengerTag();

            $message = ConversationMessage::query()->create([
                'conversation_id' => $locked->id,
                'channel' => $locked->channel,
                'direction' => MessageDirection::Out,
                'kind' => MessageKind::Text,
                'body' => $body,
                'meta' => $tag === null ? null : ['tag' => $tag],
                'sender_user_id' => $by->id,
                'delivery_status' => DeliveryStatus::Queued,
            ]);

            $locked->last_message_at = now();
            $locked->last_message_preview = MessagePreview::of($body);
            $locked->last_message_direction = MessageDirection::Out->value;
            $locked->needs_human = false;
            $locked->unread_count = 0;
            $locked->save();

            return $message;
        });

        SendInboxMessage::dispatch($message->id);

        return $message;
    }
}
