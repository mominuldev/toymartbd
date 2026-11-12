<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\DTOs\ProductListingFilters;
use App\Domain\Catalog\Services\ProductListing;
use App\Domain\Search\Support\MeilisearchClients;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Meilisearch\Contracts\DocumentsQuery;
use Throwable;

/**
 * Does the index lag the database? (P4-CAT-14 acceptance: "index-lag alert fires".)
 *
 * Two probes, run every five minutes by the scheduler:
 *
 * 1. Count — searchable products in MySQL vs documents in the index (beyond
 *    `search.lag.count_tolerance`, and not while Meilisearch is still indexing).
 * 2. Freshness — the `search.lag.sample_size` most recently updated listable products that
 *    were saved more than `search.lag.grace_seconds` ago: each document's `updated_ts`
 *    must have caught up with the row's `updated_at`, and the document must exist.
 *
 * The result is cached for the health endpoint (`checks.search_index`).
 */
final readonly class IndexLagCheck
{
    public const string CACHE_KEY = 'search:index-lag';

    /** A result older than this is itself a failure: the scheduler stopped running it. */
    public const int STALE_AFTER_SECONDS = 900;

    public function __construct(
        private MeilisearchClients $clients,
        private ProductListing $listing,
    ) {}

    /**
     * @return array{ok: bool, expected: int, indexed: int|null, stale: list<int>, missing: list<int>, error: string|null, checked_at: string}
     */
    public function run(): array
    {
        $expected = $this->listing->filtered(new ProductListingFilters)->toBase()->count();
        $result = ['ok' => true, 'expected' => $expected, 'indexed' => null, 'stale' => [], 'missing' => [], 'error' => null, 'checked_at' => now()->toIso8601String()];

        try {
            $index = $this->clients->forIndexing()->index($this->clients->index());
            $stats = $index->stats();
            $result['indexed'] = (int) ($stats['numberOfDocuments'] ?? 0);
            $indexing = (bool) ($stats['isIndexing'] ?? false);

            $grace = (int) config('search.lag.grace_seconds', 60);
            $rows = $this->listing->filtered(new ProductListingFilters)
                ->where('products.updated_at', '<=', now()->subSeconds($grace))
                ->orderByDesc('products.updated_at')
                ->limit((int) config('search.lag.sample_size', 200))
                ->toBase()
                ->get(['products.id', 'products.updated_at']);

            $docs = [];
            if ($rows->isNotEmpty()) {
                $found = $index->getDocuments((new DocumentsQuery)
                    ->setIds($rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all())
                    ->setFields(['id', 'updated_ts'])
                    ->setLimit($rows->count()));
                foreach ($found->getResults() as $doc) {
                    $docs[(int) $doc['id']] = (int) ($doc['updated_ts'] ?? 0);
                }
            }

            foreach ($rows as $row) {
                $id = (int) $row->id;
                $updated = Carbon::parse((string) $row->updated_at)->getTimestamp();
                if (! array_key_exists($id, $docs)) {
                    $result['missing'][] = $id;
                } elseif ($docs[$id] < $updated) {
                    $result['stale'][] = $id;
                }
            }

            $countOff = abs($expected - $result['indexed']) > (int) config('search.lag.count_tolerance', 0);
            $result['ok'] = $result['missing'] === [] && $result['stale'] === [] && ! ($countOff && ! $indexing);
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['error'] = $e::class.': '.$e->getMessage();
        }

        Cache::put(self::CACHE_KEY, $result, self::STALE_AFTER_SECONDS * 4);

        return $result;
    }

    /**
     * The last result, for the health endpoint: true = in sync, false = lagging or the check
     * is overdue, null = never run.
     */
    public static function lastHealthy(): ?bool
    {
        $last = Cache::get(self::CACHE_KEY);
        if (! is_array($last) || ! is_string($last['checked_at'] ?? null)) {
            return null;
        }

        $fresh = Carbon::parse($last['checked_at'])->greaterThan(now()->subSeconds(self::STALE_AFTER_SECONDS));

        return $fresh && ($last['ok'] ?? false) === true;
    }
}
