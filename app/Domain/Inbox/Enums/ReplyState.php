<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Enums;

/**
 * What staff may send in a conversation right now ({@see \App\Domain\Inbox\Services\ReplyWindow}).
 *
 * - `open` — a normal free-form reply: live chat always; WhatsApp and Messenger within 24 hours
 *   of the customer's last message (Meta's customer-service window).
 * - `human_agent` — Messenger only, 24 hours to 7 days after the customer's last message, and
 *   only with the `inbox.messenger.human_agent` switch on: a free-form reply sent with Meta's
 *   `HUMAN_AGENT` message tag.
 * - `template_only` — WhatsApp after the window closed: only an approved template.
 * - `closed` — nothing can be sent (Messenger past 24 h with the switch off, or past 7 days;
 *   or a Meta thread the customer never wrote in).
 */
enum ReplyState: string
{
    case Open = 'open';
    case HumanAgent = 'human_agent';
    case TemplateOnly = 'template_only';
    case Closed = 'closed';

    /** May a free-form text reply be sent? */
    public function allowsFreeForm(): bool
    {
        return $this === self::Open || $this === self::HumanAgent;
    }

    /** The Messenger message tag a free-form reply needs in this state. */
    public function messengerTag(): ?string
    {
        return $this === self::HumanAgent ? 'HUMAN_AGENT' : null;
    }
}
