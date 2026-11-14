<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/** Where a conversation happens (Figma B · 22 channel chips). */
enum InboxChannel: string
{
    case WhatsApp = 'whatsapp';
    case Messenger = 'messenger';
    case LiveChat = 'live_chat';

    /**
     * Meta's customer-service window applies: a free-form reply is allowed only within
     * `inbox.service_window_hours` of the customer's last message. Live chat has none.
     */
    public function hasServiceWindow(): bool
    {
        return $this !== self::LiveChat;
    }

    /** Channels that receive Meta webhooks at /api/v1/webhooks/inbox/{channel}. */
    public function receivesMetaWebhooks(): bool
    {
        return $this === self::WhatsApp || $this === self::Messenger;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
