<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

/**
 * The ONE WhatsApp Cloud API number the store has, as the notification driver and the template
 * sync need it. The values are the Inbox's (`inbox.whatsapp.*` in `settings`, env fallback) —
 * one WhatsApp number answers customers and sends order updates, so the secret is stored once.
 * Built per call by {@see \App\Domain\Notification\Contracts\WhatsAppCloudAccount}; never logged,
 * never serialised onto a queue.
 */
final readonly class WhatsAppCloudCredentials
{
    public function __construct(
        public ?string $phoneNumberId,
        public ?string $accessToken,
        public ?string $businessAccountId,
        public string $graphBaseUrl,
        public string $graphVersion,
        public int $timeout,
    ) {}

    public function canSend(): bool
    {
        return $this->phoneNumberId !== null && $this->accessToken !== null;
    }

    public function canListTemplates(): bool
    {
        return $this->businessAccountId !== null && $this->accessToken !== null;
    }

    /** `https://graph.facebook.com/v27.0/{path}` — every path segment is encoded by the caller. */
    public function url(string $path): string
    {
        return rtrim($this->graphBaseUrl, '/').'/'.trim($this->graphVersion, '/').'/'.ltrim($path, '/');
    }
}
