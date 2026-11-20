<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Queries;

use App\Domain\Inbox\Enums\ConversationStatus;
use App\Domain\Inbox\Enums\DeliveryStatus;
use App\Domain\Inbox\Enums\InboxChannel;
use App\Domain\Inbox\Enums\MessageDirection;
use App\Domain\Inbox\Models\Conversation;
use App\Domain\Inbox\Models\ConversationMessage;
use App\Domain\Inbox\Models\InboxWebhookReceipt;
use Illuminate\Support\Carbon;

/**
 * The counts on a channel card (Inbox › Channels, Figma B · 43/44): open chats, chats waiting on
 * a staff reply (`needs_human`, the same flag as the sidebar badge), unread messages, the oldest
 * wait, the last customer message, and the last webhook Meta delivered. Read-only, from the
 * inbox tables — nothing is asked of Meta.
 *
 * Also the newest send that Meta refused because the token was invalid (Graph error 190), which
 * turns the card to "Reconnect needed" without anyone pressing Test connection.
 *
 * @phpstan-type Activity array{open: int, waiting: int, unread: int, oldest_waiting_at: ?string, last_inbound_at: ?string, last_inbound_from: ?string, last_event_at: ?string}
 */
final readonly class InboxChannelActivity
{
    /** @return Activity */
    public function for(InboxChannel $channel): array
    {
        $open = Conversation::query()->where('channel', $channel->value)->where('status', ConversationStatus::Open->value);

        $totals = (clone $open)->selectRaw('COUNT(*) AS open_count, COALESCE(SUM(unread_count), 0) AS unread_sum, SUM(CASE WHEN needs_human THEN 1 ELSE 0 END) AS waiting_count')->first();
        $oldest = (clone $open)->where('needs_human', true)->min('last_inbound_at');
        $last = Conversation::query()->where('channel', $channel->value)->whereNotNull('last_inbound_at')->orderByDesc('last_inbound_at')->first(['contact_name', 'last_inbound_at']);
        $event = InboxWebhookReceipt::query()->where('channel', $channel->value)->max('created_at');

        return [
            'open' => (int) ($totals->getAttribute('open_count') ?? 0),
            'waiting' => (int) ($totals->getAttribute('waiting_count') ?? 0),
            'unread' => (int) ($totals->getAttribute('unread_sum') ?? 0),
            'oldest_waiting_at' => self::iso($oldest),
            'last_inbound_at' => $last?->last_inbound_at?->toIso8601String(),
            'last_inbound_from' => $last?->contact_name,
            'last_event_at' => self::iso($event),
        ];
    }

    /** When the newest send on this channel failed with Graph error 190 (token invalid), or null. */
    public function lastTokenFailureAt(InboxChannel $channel): ?Carbon
    {
        $at = ConversationMessage::query()
            ->where('channel', $channel->value)
            ->where('direction', MessageDirection::Out->value)
            ->where('delivery_status', DeliveryStatus::Failed->value)
            ->where('failure_code', 'provider_error:190')
            ->max('failed_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    private static function iso(mixed $value): ?string
    {
        return $value !== null ? Carbon::parse($value)->toIso8601String() : null;
    }
}
