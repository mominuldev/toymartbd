<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

use App\Support\Money;

/**
 * What an SMS gateway reported for one send attempt.
 *
 * `accepted` means the provider accepted the message for delivery, not that the handset
 * received it. `segments` is what the message is billed as (UCS-2 Bangla: 70/67 chars a
 * segment; GSM-7: 160/153). `cost` comes from config/notifications.php — never from a
 * provider response we have not verified.
 */
final readonly class SmsResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public int $segments,
        public Money $cost,
        public ?string $error = null,
    ) {}

    public static function accepted(?string $providerMessageId, int $segments, Money $cost): self
    {
        return new self(true, $providerMessageId, $segments, $cost);
    }

    public static function rejected(string $error, int $segments = 0, ?Money $cost = null): self
    {
        return new self(false, null, $segments, $cost ?? Money::zero(), $error);
    }
}
