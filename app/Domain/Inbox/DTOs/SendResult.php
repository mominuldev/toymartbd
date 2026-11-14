<?php

declare(strict_types=1);

namespace App\Domain\Inbox\DTOs;

/** A provider's answer to a send: accepted with its message id, or refused with a stable code. */
final readonly class SendResult
{
    private function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public ?string $code,
        public ?string $reason,
    ) {}

    public static function accepted(string $providerMessageId): self
    {
        return new self(true, $providerMessageId, null, null);
    }

    /** `$code`: `outside_window`, `not_configured`, `provider_error:{code}` … */
    public static function rejected(string $code, string $reason): self
    {
        return new self(false, null, $code, mb_substr($reason, 0, 300));
    }
}
