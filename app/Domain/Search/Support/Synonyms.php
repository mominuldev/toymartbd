<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use InvalidArgumentException;

/**
 * Turns the reviewed synonym groups in config/search.php into Meilisearch's synonym
 * map. A group is mutually equivalent, so every term maps to every other term (and
 * every Unicode spelling of each — see SearchText::spellings()).
 */
final class Synonyms
{
    /**
     * @param  array<array-key, mixed>  $groups
     * @return array<string, list<string>>
     */
    public static function toMeilisearch(array $groups): array
    {
        $map = [];

        foreach (self::groups($groups) as $group) {
            $terms = [];
            foreach ($group as $term) {
                foreach (SearchText::spellings($term) as $spelling) {
                    $terms[$spelling] = true;
                }
            }
            $terms = array_keys($terms);

            foreach ($terms as $term) {
                $others = array_values(array_filter($terms, static fn (string $t): bool => $t !== $term));
                $map[$term] = array_values(array_unique([...($map[$term] ?? []), ...$others]));
            }
        }

        ksort($map);

        return $map;
    }

    /**
     * Every term equivalent to `$word` (itself included), for the database fallback and the
     * spelling suggester.
     *
     * @param  array<array-key, mixed>  $groups
     * @return list<string>
     */
    public static function expand(string $word, array $groups): array
    {
        static $cache = [];
        $hash = md5(serialize($groups));
        $map = $cache[$hash] ??= self::toMeilisearch($groups);
        $key = SearchText::key($word);

        return array_values(array_unique([$key, ...($map[$key] ?? [])]));
    }

    /**
     * Validates the config shape: a list of groups, each a list of 2+ non-empty strings, no
     * term in two groups (that would silently merge two meanings).
     *
     * @param  array<array-key, mixed>  $groups
     * @return list<list<string>>
     */
    public static function groups(array $groups): array
    {
        $out = [];
        $seen = [];

        foreach (array_values($groups) as $i => $group) {
            if (! is_array($group) || count($group) < 2) {
                throw new InvalidArgumentException("search.synonyms[{$i}] must list at least two terms.");
            }

            $terms = [];
            foreach ($group as $term) {
                if (! is_string($term) || SearchText::key($term) === '') {
                    throw new InvalidArgumentException("search.synonyms[{$i}] contains an empty or non-string term.");
                }
                $key = SearchText::key($term);
                if (isset($seen[$key]) && $seen[$key] !== $i) {
                    throw new InvalidArgumentException("Synonym term \"{$key}\" appears in two groups ({$seen[$key]} and {$i}).");
                }
                $seen[$key] = $i;
                $terms[] = $key;
            }

            $out[] = array_values(array_unique($terms));
        }

        return $out;
    }
}
