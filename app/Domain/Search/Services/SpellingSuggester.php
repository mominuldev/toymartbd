<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\Enums\BrandStatus;
use App\Domain\Catalog\Enums\CategoryStatus;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Engines\ProductSearchEngine;
use App\Domain\Search\Exceptions\SearchUnavailable;
use App\Domain\Search\Support\SearchText;
use App\Domain\Search\Support\Synonyms;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Did you mean …?" for a zero-result search (storefront spec B6.3 §3).
 *
 * Meilisearch has already forgiven one typo per word (two from seven letters) and drops
 * trailing words that match nothing, so a query that still finds nothing is usually
 * further off. Candidates, each offered only if it actually returns products:
 *
 * 1. every word replaced by its nearest catalogue word (brand, category and product-name
 *    words plus the synonym vocabulary, in both scripts) within a looser edit distance;
 * 2. each single word of a multi-word query on its own ("lego starwars 9000" → "lego").
 */
final readonly class SpellingSuggester
{
    public const int MAX = 3;

    private const string CACHE_KEY = 'search:vocabulary:v1';

    private const int CACHE_SECONDS = 3600;

    public function __construct(private ProductSearchEngine $engine) {}

    /** @return list<array{query: string, count: int}> */
    public function suggest(SearchRequest $request): array
    {
        $words = SearchText::words($request->query);
        if ($words === []) {
            return [];
        }

        $candidates = [];
        $corrected = $this->correct($words);
        if ($corrected !== null) {
            $candidates[] = $corrected;
        }
        if (count($words) > 1) {
            foreach ($words as $word) {
                if (mb_strlen($word) >= 2 && ! is_numeric($word)) {
                    $candidates[] = $this->correct([$word]) ?? $word;
                }
            }
        }

        $out = [];
        $original = SearchText::key($request->query);
        foreach (array_values(array_unique($candidates)) as $candidate) {
            if ($candidate === $original || count($out) >= self::MAX) {
                continue;
            }
            try {
                $count = $this->engine->count($request->withQuery($candidate));
            } catch (SearchUnavailable) {
                return $out;
            }
            if ($count > 0) {
                $out[] = ['query' => $candidate, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * The query with every word not in the vocabulary replaced by its nearest vocabulary
     * word; null when nothing changed.
     *
     * @param  list<string>  $words
     */
    public function correct(array $words): ?string
    {
        $vocabulary = $this->vocabulary();
        $changed = false;
        $out = [];

        foreach ($words as $word) {
            $length = mb_strlen($word);
            if (isset($vocabulary[$word]) || $length < 3 || is_numeric($word)) {
                $out[] = $word;

                continue;
            }

            $limit = $length <= 4 ? 1 : ($length <= 7 ? 2 : 3);
            $best = null;
            $bestDistance = $limit + 1;
            foreach ($vocabulary as $term => $_) {
                $term = (string) $term;
                if (abs(mb_strlen($term) - $length) > $limit) {
                    continue;
                }
                $distance = SearchText::distance($word, $term, $limit);
                if ($distance < $bestDistance) {
                    [$best, $bestDistance] = [$term, $distance];
                }
            }

            $out[] = $best ?? $word;
            $changed = $changed || $best !== null;
        }

        return $changed ? implode(' ', $out) : null;
    }

    /**
     * Lower-cased, NFC words of 3+ letters from live brand, category and product names (both
     * locales) and from the synonym table. Cached for an hour.
     *
     * @return array<string, true>
     */
    public function vocabulary(): array
    {
        /** @var array<string, true> */
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, static function (): array {
            $texts = [
                ...DB::table('brands')->where('status', BrandStatus::Active->value)->pluck('name')->all(),
                ...DB::table('brands')->where('status', BrandStatus::Active->value)->whereNotNull('name_bn')->pluck('name_bn')->all(),
                ...DB::table('categories')->where('status', CategoryStatus::Active->value)->whereNull('deleted_at')->pluck('name')->all(),
                ...DB::table('categories')->where('status', CategoryStatus::Active->value)->whereNull('deleted_at')->whereNotNull('name_bn')->pluck('name_bn')->all(),
                ...DB::table('products')->where('status', ProductStatus::Active->value)->whereNull('deleted_at')->pluck('name')->all(),
                ...DB::table('products')->where('status', ProductStatus::Active->value)->whereNull('deleted_at')->whereNotNull('name_bn')->pluck('name_bn')->all(),
                ...array_merge(...Synonyms::groups((array) config('search.synonyms', []))),
            ];

            $vocabulary = [];
            foreach ($texts as $text) {
                foreach (SearchText::words((string) $text) as $word) {
                    if (mb_strlen($word) >= 3 && ! is_numeric($word)) {
                        $vocabulary[$word] = true;
                    }
                }
            }

            return $vocabulary;
        });
    }
}
