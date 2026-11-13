<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Locations;

/**
 * Name normalisation for courier locations. `exact()` is used to choose between rows a
 * human has ALREADY mapped; `similarity()` only ranks SUGGESTIONS a human must confirm.
 * Neither is ever used to map a row (rule 32 — Cumilla/Comilla is exactly why).
 */
final class LocationName
{
    /** Generic words that carry no identity ("Comilla Sadar" ~ "Cumilla"). Suggestions only. */
    private const array NOISE = ['sadar', 'upazila', 'upazilla', 'thana', 'city', 'corporation', 'municipality', 'pourashava', 'zone', 'district', 'model'];

    /** Lower-case ASCII words, punctuation and repeated spaces removed. */
    public static function exact(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = (string) preg_replace("/[\\'\x{2019}`.]/u", '', $name);
        $name = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name);

        return trim($name);
    }

    /** {@see exact()} minus the {@see NOISE} words. */
    public static function core(string $name): string
    {
        $words = array_filter(explode(' ', self::exact($name)), static fn (string $w): bool => $w !== '' && ! in_array($w, self::NOISE, true));

        return implode(' ', $words);
    }

    /** 0..1 — edit-distance ratio of the core names, 1.0 when one contains the other as a word run. */
    public static function similarity(string $a, string $b): float
    {
        $a = self::core($a);
        $b = self::core($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        if (str_contains(' '.$a.' ', ' '.$b.' ') || str_contains(' '.$b.' ', ' '.$a.' ')) {
            return 0.9;
        }

        $max = max(strlen($a), strlen($b));
        if ($max > 255) {
            return 0.0;
        }

        return max(0.0, 1 - levenshtein($a, $b) / $max);
    }
}
