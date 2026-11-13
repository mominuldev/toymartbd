<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Signed, expiring preview tokens for unpublished content (blog posts today; CMS pages and
 * homepage drafts may share it). The token is `{type}.{id}.{expires}.{hmac}` — HMAC-SHA256
 * under the app key over `"{type}.{id}.{expires}"` — so it names exactly one record of one
 * type, cannot be forged or widened, and stops working at `expires` (unix seconds).
 *
 * The admin mints one; the storefront passes it to the API unchanged; the API verifies it.
 * Preview pages are `noindex` and uncached.
 */
final class PreviewToken
{
    public const int DEFAULT_TTL_SECONDS = 86_400;

    private const string PATTERN = '/^([a-z_]{1,32})\.(\d{1,12})\.(\d{1,12})\.([a-f0-9]{64})$/';

    public static function issue(string $type, int $id, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $expires = CarbonImmutable::now()->getTimestamp() + $ttlSeconds;
        $payload = $type.'.'.$id.'.'.$expires;

        return $payload.'.'.self::sign($payload);
    }

    /** The record id the token grants, or null when it is malformed, forged, for another type, or expired. */
    public static function verify(string $token, string $type): ?int
    {
        if (preg_match(self::PATTERN, $token, $m) !== 1 || $m[1] !== $type) {
            return null;
        }

        $payload = $m[1].'.'.$m[2].'.'.$m[3];
        if (! hash_equals(self::sign($payload), $m[4])) {
            return null;
        }

        if ((int) $m[3] < CarbonImmutable::now()->getTimestamp()) {
            return null;
        }

        return (int) $m[2];
    }

    private static function sign(string $payload): string
    {
        $key = config('app.key');

        return hash_hmac('sha256', 'preview|'.$payload, is_string($key) ? $key : '');
    }
}
