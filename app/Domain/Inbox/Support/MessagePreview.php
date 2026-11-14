<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Support;

/** The list row's one-line preview: the text, trimmed to one line, or `[image]` for a non-text message. */
final class MessagePreview
{
    public static function of(?string $body, ?string $unsupportedType = null): string
    {
        if ($body === null || trim($body) === '') {
            return '['.($unsupportedType ?? 'message').']';
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $body)), 0, 200);
    }
}
