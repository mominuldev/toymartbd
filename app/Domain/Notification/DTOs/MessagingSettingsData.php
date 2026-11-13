<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

/**
 * One save of Admin → Integrations → Messaging. Non-secret values replace what is stored
 * (null = remove the saved value, so env applies again). For each secret: a non-null
 * value replaces it, null keeps the existing one, and `clear…` removes it.
 */
final readonly class MessagingSettingsData
{
    public function __construct(
        public string $smsDriver,
        public ?string $reveBaseUrl,
        public ?string $reveSenderId,
        public string $costPerSegment,
        public bool $whatsappEnabled,
        public ?string $reveApiKey = null,
        public ?string $reveSecretKey = null,
        public bool $clearReveApiKey = false,
        public bool $clearReveSecretKey = false,
    ) {}
}
