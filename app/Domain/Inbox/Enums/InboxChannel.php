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
     * The storefront Contact page's "Send us a message" form (owner decision 2026-11-16). Inbound
     * only: there is nothing to send a reply through, so staff answer by WhatsApp or a call.
     */
    case WebForm = 'web_form';

    /**
     * Meta's customer-service window applies: a free-form reply is allowed only within
     * `inbox.service_window_hours` of the customer's last message. Live chat and the web form
     * have none.
     */
    public function hasServiceWindow(): bool
    {
        return $this === self::WhatsApp || $this === self::Messenger;
    }

    /** Can staff send anything to the customer on this channel? Never on the web form. */
    public function canSend(): bool
    {
        return $this !== self::WebForm;
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
