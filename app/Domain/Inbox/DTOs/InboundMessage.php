<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

use Illuminate\Support\Carbon;

/** A customer's message as a channel reported it, after signature verification. */
final readonly class InboundMessage
{
    public function __construct(
        public string $threadId,
        public string $providerMessageId,
        public ?string $body,
        public Carbon $sentAt,
        public ?string $contactName = null,
        /** E.164 when the channel gives a valid Bangladeshi mobile (rule 30), else null. */
        public ?string $phoneE164 = null,
        /** The provider's message type when it is not plain text (image, audio, …). */
        public ?string $unsupportedType = null,
    ) {}
}
