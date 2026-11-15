<?php

declare(strict_types=1);

namespace App\Domain\Notification\Exceptions;

use RuntimeException;

/**
 * "Sync from Meta" could not read the template list. Not configured, or Meta refused the
 * credentials (a Graph error such as 190 = token invalid, 100 = bad Business Account id) → 409:
 * staff fix the settings. Meta unreachable → 503. Messages are safe to show: a Graph error code,
 * never a token or a URL.
 */
final class WhatsAppTemplateSyncFailed extends RuntimeException
{
    private function __construct(string $message, public readonly bool $configuration)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self((string) __('notifications.whatsapp_sync_not_configured'), true);
    }

    public static function provider(string $code): self
    {
        return new self((string) __('notifications.whatsapp_sync_failed', ['code' => $code]), $code !== 'unreachable');
    }
}
