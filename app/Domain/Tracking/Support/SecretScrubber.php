<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Support;

/**
 * Last line of defence for rule 20: removes every configured secret (and any
 * api_secret= / access_token= query value) from a string before it is stored, returned or
 * logged, and caps it at 2 KB for DispatchResult::$responseExcerpt.
 */
final class SecretScrubber
{
    public const int MAX_EXCERPT_BYTES = 2048;

    /**
     * @param  list<string|null>  $secrets
     */
    public static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $text = str_replace([$secret, rawurlencode($secret)], '[redacted]', $text);
            }
        }

        return preg_replace('/((?:api_secret|access_token)=)[^&\s"\']+/i', '$1[redacted]', $text) ?? '';
    }

    /**
     * @param  list<string|null>  $secrets
     */
    public static function excerpt(string $text, array $secrets): string
    {
        $text = self::scrub($text, $secrets);

        return strlen($text) > self::MAX_EXCERPT_BYTES ? mb_strcut($text, 0, self::MAX_EXCERPT_BYTES) : $text;
    }
}
