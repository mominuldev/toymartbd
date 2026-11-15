<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use App\Domain\Inbox\Enums\InboxChannel;

/**
 * One message to send. `recipient` is the provider's thread id (wa_id / PSID) — never logged.
 *
 * `template` set: a WhatsApp template send (the body is only our rendered preview; the
 * provider renders its approved copy from the name, language and parameters). `tag` set: a
 * Messenger reply with that message tag (`HUMAN_AGENT`) instead of `messaging_type: RESPONSE`.
 */
final readonly class OutboundMessage
{
    public function __construct(
        public InboxChannel $channel,
        public int $messageId,
        public string $recipient,
        public string $body,
        public ?OutboundTemplate $template = null,
        public ?string $tag = null,
    ) {}
}
