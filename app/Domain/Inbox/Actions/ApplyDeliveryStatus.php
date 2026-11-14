<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Actions;

use App\Domain\Inbox\DTOs\StatusUpdate;
use App\Domain\Inbox\DTOs\WatermarkUpdate;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Enums\MessageKind;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;

/**
 * A provider's delivery status for our outbound messages. Only moves forward
 * (sent → delivered → read), or to failed before delivery; a replay or an out-of-order
 * status changes nothing. Statuses for unknown ids (sent from another tool, or arriving
 * before the send job stored the id) are ignored. Row-at-a-time saves, no transaction.
 */
final readonly class ApplyDeliveryStatus
{
    public function handle(InboxChannel $channel, StatusUpdate $update): bool
    {
        $message = ConversationMessage::query()
            ->where('channel', $channel->value)
            ->where('provider_message_id', $update->providerMessageId)
            ->where('direction', MessageDirection::Out->value)
            ->first();

        return $message !== null && $this->advance($message, $update->status, $update);
    }

    /** Messenger's watermark: every message we sent in the thread up to it. */
    public function watermark(InboxChannel $channel, WatermarkUpdate $update): int
    {
        $conversation = Conversation::query()->where('channel', $channel->value)->where('external_thread_id', $update->threadId)->first();
        if ($conversation === null) {
            return 0;
        }

        $changed = 0;
        $messages = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Out->value)
            ->where('kind', MessageKind::Text->value)
            ->whereNotNull('sent_at')
            ->where('sent_at', '<=', $update->watermark)
            ->whereIn('delivery_status', [DeliveryStatus::Sent->value, DeliveryStatus::Delivered->value])
            ->get();
        foreach ($messages as $message) {
            $changed += $this->advance($message, $update->status, null) ? 1 : 0;
        }

        return $changed;
    }

    private function advance(ConversationMessage $message, DeliveryStatus $next, ?StatusUpdate $update): bool
    {
        $current = $message->delivery_status ?? DeliveryStatus::Queued;
        if (! $current->canAdvanceTo($next)) {
            return false;
        }

        $at = $update !== null ? $update->at : now();
        $message->delivery_status = $next;
        match ($next) {
            DeliveryStatus::Sent => $message->sent_at ??= $at,
            DeliveryStatus::Delivered => $message->delivered_at = $at,
            DeliveryStatus::Read => $message->read_at = $at,
            DeliveryStatus::Failed => $message->failed_at = $at,
            DeliveryStatus::Queued => null,
        };
        if ($next === DeliveryStatus::Read && $message->delivered_at === null) {
            $message->delivered_at = $at;
        }
        if ($next === DeliveryStatus::Failed && $update !== null) {
            $message->failure_code = $update->errorCode === null ? 'provider_failed' : 'provider_error:'.$update->errorCode;
            $message->failure_reason = $update->errorTitle;
        }
        $message->save();

        return true;
    }
}
