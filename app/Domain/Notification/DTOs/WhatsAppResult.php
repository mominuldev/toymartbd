<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

use App\Support\Money;

/** What a WhatsApp gateway reported for one template send. Cost comes from config. */
final readonly class WhatsAppResult
{
    public function __construct(
        public bool $accepted,
        public ?string $providerMessageId,
        public Money $cost,
        public ?string $error = null,
    ) {}

    public static function accepted(?string $providerMessageId, Money $cost): self
    {
        return new self(true, $providerMessageId, $cost);
    }

    public static function rejected(string $error): self
    {
        return new self(false, null, Money::zero(), $error);
    }
}
