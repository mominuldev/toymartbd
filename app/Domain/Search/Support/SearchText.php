<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use Normalizer;

/**
 * Text normalisation shared by the index documents, the synonym table and queries,
 * so all three agree on one spelling of every Bangla word.
 *
 * Bangla has two Unicode spellings of ড় / ঢ় / য়: the precomposed code point
 * (U+09DC / U+09DD / U+09DF) and the letter + nukta (U+09A1 U+09BC …). Keyboards and
 * copy-paste produce both, and NFC turns the precomposed form INTO the nukta form (they
 * are composition exclusions). We normalise everything to NFC — the nukta spelling —
 * and still give Meilisearch both spellings of every synonym in case a document slipped
 * past normalisation.
 */
final class SearchText
{
    private const array PRECOMPOSED = [
        "\u{09A1}\u{09BC}" => "\u{09DC}", // ড় RRA
        "\u{09A2}\u{09BC}" => "\u{09DD}", // ঢ় RHA
        "\u{09AF}\u{09BC}" => "\u{09DF}", // য় YYA
    ];

    /** NFC, collapsed whitespace, trimmed. Case is left alone (Meilisearch folds it). */
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $nfc = Normalizer::normalize($text, Normalizer::FORM_C);
        $text = is_string($nfc) ? $nfc : $text;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Lower-cased and normalised: the comparison key for synonyms and spelling. */
    public static function key(string $text): string
    {
        return mb_strtolower(self::normalize($text));
    }

    /**
     * Every Unicode spelling of a term: the NFC (nukta) form plus the precomposed form
     * when they differ.
     *
     * @return list<string>
     */
    public static function spellings(string $term): array
    {
        $nfc = self::key($term);
        $precomposed = strtr($nfc, self::PRECOMPOSED);

        return array_values(array_unique([$nfc, $precomposed]));
    }

    /**
     * Words of a query (letters and digits in any script, plus the Bangla vowel signs and
     * virama, which are combining marks).
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', self::key($text), $m);

        return array_values(array_filter($m[0], static fn (string $w): bool => $w !== ''));
    }

    /**
     * Levenshtein distance over Unicode code points (PHP's levenshtein() counts bytes, so
     * one Bangla letter would cost three edits).
     */
    public static function distance(string $a, string $b, int $max = PHP_INT_MAX): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);

        if (abs($n - $m) > $max) {
            return $max + 1;
        }
        if ($n === 0 || $m === 0) {
            return max($n, $m);
        }

        $previous = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $current[$j] = min($previous[$j] + 1, $current[$j - 1] + 1, $previous[$j - 1] + $cost);
                $rowMin = min($rowMin, $current[$j]);
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
            $previous = $current;
        }

        return $previous[$m];
    }

    /**
     * Masks long digit runs before a query is logged: a shopper sometimes pastes a phone
     * number into the search box, and CLAUDE.md rule 20 forbids logging one.
     */
    public static function redact(string $query): string
    {
        return (string) preg_replace('/\d{5,}/', '[digits]', $query);
    }
}
