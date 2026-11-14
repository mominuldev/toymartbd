<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Customer\Models\Customer;
use App\Domain\Inbox\DTOs\InboundMessage;
use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Enums\SystemEvent;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Support\MessagePreview;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Appends one verified inbound message to its conversation, creating the conversation on the
 * first message of a thread. IDEMPOTENT on (channel, provider message id): a replayed webhook
 * returns null and changes nothing.
 *
 * The customer is linked by phone when the channel gives one (WhatsApp) and a customer with
 * that E.164 number exists — a read of Customer, never a write (rule 2). A message on a
 * resolved conversation reopens it with a system line. The customer's message opens or
 * extends Meta's 24-hour window (`last_inbound_at`) and marks the thread as waiting for a
 * staff reply (`needs_human`).
 */
final readonly class IngestInboundMessage
{
    public function handle(InboxChannel $channel, InboundMessage $inbound): ?ConversationMessage
    {
        return DB::transaction(function () use ($channel, $inbound): ?ConversationMessage {
            $conversation = $this->conversationFor($channel, $inbound);

            if (ConversationMessage::query()->where('channel', $channel->value)->where('provider_message_id', $inbound->providerMessageId)->exists()) {
                return null;
            }

            try {
                $message = ConversationMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'channel' => $channel,
                    'direction' => MessageDirection::In,
                    'kind' => MessageKind::Text,
                    'body' => $inbound->body === null ? null : mb_substr($inbound->body, 0, 10000),
                    'meta' => $inbound->unsupportedType === null ? null : ['type' => $inbound->unsupportedType],
                    'provider_message_id' => $inbound->providerMessageId,
                    'provider_sent_at' => $inbound->sentAt,
                ]);
            } catch (UniqueConstraintViolationException) {
                return null; // A concurrent delivery of the same message won.
            }

            if ($conversation->status === ConversationStatus::Resolved) {
                $conversation->status = ConversationStatus::Open;
                $conversation->resolved_at = null;
                ConversationMessage::query()->create([
                    'conversation_id' => $conversation->id,
                    'channel' => $channel,
                    'direction' => MessageDirection::Out,
                    'kind' => MessageKind::System,
                    'system_event' => SystemEvent::Reopened,
                    'meta' => ['by' => 'customer'],
                ]);
            }

            if ($inbound->contactName !== null) {
                $conversation->contact_name = $inbound->contactName;
            }
            if ($conversation->contact_phone === null && $inbound->phoneE164 !== null) {
                $conversation->contact_phone = $inbound->phoneE164;
            }
            if ($conversation->customer_id === null && $conversation->contact_phone !== null) {
                $conversation->customer_id = Customer::findByPhone($conversation->contact_phone)?->id;
            }

            if ($conversation->last_message_at === null || ! $inbound->sentAt->isBefore($conversation->last_message_at)) {
                $conversation->last_message_at = $inbound->sentAt;
                $conversation->last_message_preview = MessagePreview::of($inbound->body, $inbound->unsupportedType);
                $conversation->last_message_direction = MessageDirection::In->value;
            }
            if ($conversation->last_inbound_at === null || $inbound->sentAt->isAfter($conversation->last_inbound_at)) {
                $conversation->last_inbound_at = $inbound->sentAt;
            }
            $conversation->unread_count++;
            $conversation->needs_human = true;
            $conversation->save();

            return $message;
        });
    }

    private function conversationFor(InboxChannel $channel, InboundMessage $inbound): Conversation
    {
        $find = fn (): ?Conversation => Conversation::query()
            ->where('channel', $channel->value)
            ->where('external_thread_id', $inbound->threadId)
            ->lockForUpdate()
            ->first();

        $existing = $find();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return Conversation::query()->create([
                'channel' => $channel,
                'external_thread_id' => $inbound->threadId,
                'status' => ConversationStatus::Open,
            ]);
        } catch (UniqueConstraintViolationException) {
            $conversation = $find();
            assert($conversation instanceof Conversation);

            return $conversation;
        }
    }
}
