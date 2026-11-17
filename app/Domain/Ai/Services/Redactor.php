<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

/**
 * Removes what must never reach an AI provider (rule 20): phone numbers (Bangladesh and
 * international forms, spaced or dashed), email addresses, and any run of 10+ digits (card,
 * account or national id numbers). Order numbers like `TMB-26-00126` survive: their digit runs
 * are short. Applied by {@see AiClient} to every user turn, after feature code has already
 * chosen only the fields it needs.
 */
final class Redactor
{
    public const string MARK = '[removed]';

    public static function clean(string $text): string
    {
        $patterns = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '/(?<![\d])(?:\+?\s*8\s*8\s*0[\s\-]*)?0?1[3-9](?:[\s\-]*\d){8}(?![\d])/u',
            '/(?<![\d])\+?\d(?:[\s\-]?\d){9,}(?![\d])/u',
        ];

        return (string) preg_replace($patterns, self::MARK, $text);
    }
}
