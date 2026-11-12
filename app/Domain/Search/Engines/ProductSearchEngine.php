<?php

declare(strict_types=1);

namespace App\Domain\Search\Engines;

use App\Domain\Search\DTOs\SearchHits;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Exceptions\SearchUnavailable;

/** Finds products for a storefront search. */
interface ProductSearchEngine
{
    /**
     * @throws SearchUnavailable when the engine cannot answer (the caller falls back)
     */
    public function search(SearchRequest $request, bool $withFacets = true): SearchHits;

    /**
     * Total hits only — for checking a spelling suggestion before offering it.
     *
     * @throws SearchUnavailable
     */
    public function count(SearchRequest $request): int;
}
