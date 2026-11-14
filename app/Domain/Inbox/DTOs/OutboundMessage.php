<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\InboxChannel;

/** One reply to send. `recipient` is the provider's thread id (wa_id / PSID) — never logged. */
final readonly class OutboundMessage
{
    public function __construct(
        public InboxChannel $channel,
        public int $messageId,
        public string $recipient,
        public string $body,
    ) {}
}
