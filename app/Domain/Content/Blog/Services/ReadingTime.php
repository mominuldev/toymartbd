<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\Services;

/**
 * Reading time, computed server-side from the sanitised body (never by a client, rule 1).
 * 200 words a minute for English; 160 for Bangla, whose words are longer and whose readers
 * on this site skim less. Always at least one minute.
 */
final class ReadingTime
{
    public const int WPM_EN = 200;

    public const int WPM_BN = 160;

    public static function minutes(?string $html, string $locale = 'en'): int
    {
        $words = self::words(BlogHtml::plainText($html));
        $wpm = $locale === 'bn' ? self::WPM_BN : self::WPM_EN;

        return max(1, (int) ceil($words / $wpm));
    }

    public static function words(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        // Split on Unicode whitespace: str_word_count() knows only Latin letters and counts no Bangla.
        return count(preg_split('/\s+/u', $text) ?: []);
    }
}
