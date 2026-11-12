<?php

declare(strict_types=1);

namespace App\Domain\Search\DTOs;

/**
 * What an engine found: product ids in rank order for the requested page, the total,
 * and raw facet counts keyed by index field (`brand_slug` → [slug => count]).
 * `facetsExcluding[field]` holds the counts with that facet's own filter removed
 * (disjunctive facets).
 */
final readonly class SearchHits
{
    /**
     * @param  list<int>  $ids
     * @param  array<string, array<string, int>>  $facets
     */
    public function __construct(
        public array $ids,
        public int $total,
        public array $facets = [],
        public bool $degraded = false,
    ) {}

    public static function none(bool $degraded = false): self
    {
        return new self([], 0, [], $degraded);
    }
}
