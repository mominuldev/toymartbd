<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * URL slugs: ASCII, transliterated, shared across locales (CLAUDE.md rule 37).
 *
 * One product has one slug, served under both / and /bn/. Bangla-script slugs
 * percent-encode into unreadable links on Messenger and WhatsApp, so a Bangla-only
 * name is transliterated ("খেলনা গাড়ি" → "khelna-gadi"). When an English name
 * exists it wins, because it is what most searches and shares use.
 */
final class Slug
{
    private const MAX_LENGTH = 120;

    public static function from(string $text): string
    {
        $slug = Str::slug($text, '-', 'bn');

        if ($slug === '') {
            throw new InvalidArgumentException('Text produced an empty slug; supply an English name.');
        }

        return rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
    }

    /** The slug for a record named in one or both locales. */
    public static function forNames(?string $english, ?string $bangla): string
    {
        foreach ([$english, $bangla] as $name) {
            if ($name !== null && trim($name) !== '' && Str::slug($name, '-', 'bn') !== '') {
                return self::from($name);
            }
        }

        throw new InvalidArgumentException('A slug needs an English or Bangla name.');
    }

    /**
     * Make a slug unique: "lego-city", then "lego-city-2", "lego-city-3" …
     *
     * @param  Closure(string): bool  $taken  returns true when a candidate is already used
     */
    public static function unique(string $base, Closure $taken): string
    {
        $candidate = $base;

        for ($n = 2; $taken($candidate); $n++) {
            $suffix = '-'.$n;
            $candidate = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;
        }

        return $candidate;
    }
}
