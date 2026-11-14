<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Support;

/**
 * Meta's webhook signature (WhatsApp Cloud API and Messenger Platform): the
 * `X-Hub-Signature-256` header is `sha256=` + the lowercase hex HMAC-SHA256 of the payload
 * keyed with the App Secret.
 *
 * Sources (read 2026-11-15):
 *  - developers.facebook.com/documentation/business-messaging/whatsapp/webhooks/create-webhook-endpoint
 *  - developers.facebook.com/docs/messenger-platform/webhooks
 *
 * We hash the RAW request bytes exactly as received, before any decoding. Messenger's page
 * warns that the signature is over the *escaped-unicode* payload Meta sends, and that
 * re-encoding decoded JSON gives a different hash — which is why we never decode first.
 * ASSUMPTION (unverified until a real account exists): the bytes Laravel hands us are the
 * bytes Meta signed.
 */
final class MetaSignature
{
    public const string HEADER = 'x-hub-signature-256';

    /** @param  array<string, list<string|null>|string|null>  $headers  lower-cased names */
    public static function verify(array $headers, string $rawBody, ?string $appSecret): bool
    {
        if ($appSecret === null || $appSecret === '') {
            return false; // No secret configured: nothing can be verified, so nothing is accepted.
        }

        $header = $headers[self::HEADER] ?? null;
        if (is_array($header)) {
            $header = $header[0] ?? null;
        }
        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $given = strtolower(substr($header, 7));
        if (preg_match('/^[0-9a-f]{64}$/', $given) !== 1) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), $given);
    }

    /** For tests and fixtures: the header value Meta would send. */
    public static function sign(string $rawBody, string $appSecret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);
    }

    /** The GET subscription handshake, shared by both Meta channels. */
    public static function handshake(?string $mode, ?string $given, ?string $expected, ?string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $expected === null || $expected === '' || $given === null || $challenge === null || $challenge === '') {
            return null;
        }

        return hash_equals($expected, $given) ? $challenge : null;
    }
}
