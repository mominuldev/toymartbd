<?php

declare(strict_types=1);

namespace App\Domain\Search\Engines;

use App\Domain\Search\DTOs\SearchHits;
use App\Domain\Search\DTOs\SearchRequest;
use App\Domain\Search\Exceptions\SearchUnavailable;
use App\Domain\Search\Support\FilterGroups;
use App\Domain\Search\Support\MeilisearchClients;
use App\Domain\Search\Support\SearchText;
use Meilisearch\Contracts\SearchQuery;
use Meilisearch\Exceptions\ExceptionInterface;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * Product search on Meilisearch (P4-CAT-14). One multi-search round trip per request:
 * the page of hits with the facets whose filter is not in use, plus one zero-hit query per
 * filter in use carrying that facet with its own filter removed — disjunctive facets
 * matching the listing's.
 */
final readonly class MeilisearchProductSearch implements ProductSearchEngine
{
    public function __construct(private MeilisearchClients $clients) {}

    public function search(SearchRequest $request, bool $withFacets = true): SearchHits
    {
        $index = $this->clients->index();
        $q = SearchText::normalize($request->query);
        $active = FilterGroups::expressions($request->filters);
        $sort = $request->sort->meilisearch();

        $main = (new SearchQuery)
            ->setIndexUid($index)
            ->setQuery($q)
            ->setAttributesToRetrieve(['id'])
            ->setHitsPerPage($request->perPage)
            ->setPage($request->page);
        $filter = FilterGroups::filter($request->filters);
        if ($filter !== null) {
            $main->setFilter([$filter]);
        }
        if ($sort !== []) {
            $main->setSort($sort);
        }

        $queries = [$main];
        $disjunctive = [];
        if ($withFacets) {
            $plain = [];
            foreach (FilterGroups::FACET_FIELDS as $facet => $field) {
                if (! isset($active[$facet])) {
                    $plain[] = $field;

                    continue;
                }
                $query = (new SearchQuery)->setIndexUid($index)->setQuery($q)->setLimit(0)->setFacets([$field]);
                $except = FilterGroups::filter($request->filters, $facet);
                if ($except !== null) {
                    $query->setFilter([$except]);
                }
                $queries[] = $query;
                $disjunctive[] = $field;
            }
            $main->setFacets($plain);
        }

        $results = $this->run($queries);

        $first = $results[0];
        $ids = array_values(array_filter(array_map(
            static fn (mixed $hit): int => is_array($hit) && is_numeric($hit['id'] ?? null) ? (int) $hit['id'] : 0,
            is_array($first['hits'] ?? null) ? $first['hits'] : [],
        )));

        $facets = self::distribution($first);
        foreach ($disjunctive as $i => $field) {
            $facets[$field] = self::distribution($results[$i + 1])[$field] ?? [];
        }

        return new SearchHits($ids, (int) ($first['totalHits'] ?? $first['estimatedTotalHits'] ?? count($ids)), $facets);
    }

    public function count(SearchRequest $request): int
    {
        $query = (new SearchQuery)
            ->setIndexUid($this->clients->index())
            ->setQuery(SearchText::normalize($request->query))
            ->setHitsPerPage(1)
            ->setPage(1)
            ->setAttributesToRetrieve(['id']);
        $filter = FilterGroups::filter($request->filters);
        if ($filter !== null) {
            $query->setFilter([$filter]);
        }

        return (int) ($this->run([$query])[0]['totalHits'] ?? 0);
    }

    /**
     * @param  list<SearchQuery>  $queries
     * @return list<array<string, mixed>>
     */
    private function run(array $queries): array
    {
        try {
            $response = $this->clients->forSearch()->multiSearch($queries);
        } catch (ExceptionInterface|ClientExceptionInterface $e) {
            throw new SearchUnavailable('Meilisearch search failed: '.$e::class, previous: $e);
        }

        $results = is_array($response) && is_array($response['results'] ?? null) ? array_values($response['results']) : [];
        if (count($results) !== count($queries)) {
            throw new SearchUnavailable('Meilisearch returned an unexpected multi-search response.');
        }

        return array_map(static fn (mixed $r): array => is_array($r) ? $r : [], $results);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, array<string, int>>
     */
    private static function distribution(array $result): array
    {
        $out = [];
        $raw = $result['facetDistribution'] ?? [];
        if (! is_array($raw)) {
            return [];
        }
        foreach ($raw as $field => $values) {
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $value => $count) {
                $out[(string) $field][(string) $value] = (int) $count;
            }
        }

        return $out;
    }
}
