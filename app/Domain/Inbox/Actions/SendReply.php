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
use App\Domain\Inbox\Support\MessagePreview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A staff reply. Records the message as `queued` inside the transaction, then — after
 * commit — queues {@see SendInboxMessage}, which makes the provider call (rule 4: never an
 * HTTP call inside a transaction).
 *
 * On WhatsApp and Messenger a free-form reply is allowed only inside Meta's 24-hour
 * customer-service window; outside it this refuses with 409 and queues nothing. The window
 * is checked again under the row lock and once more in the job.
 */
final readonly class SendReply
{
    public function handle(Conversation $conversation, User $by, string $body): ConversationMessage
    {
        if (! $conversation->windowOpen()) {
            throw OutsideServiceWindow::make();
        }

        $message = DB::transaction(function () use ($conversation, $by, $body): ConversationMessage {
            /** @var Conversation $locked */
            $locked = Conversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if (! $locked->windowOpen()) {
                throw OutsideServiceWindow::make();
            }

            $message = ConversationMessage::query()->create([
                'conversation_id' => $locked->id,
                'channel' => $locked->channel,
                'direction' => MessageDirection::Out,
                'kind' => MessageKind::Text,
                'body' => $body,
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
