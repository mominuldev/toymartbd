<?php

declare(strict_types=1);

namespace App\Domain\Search\Jobs;

use App\Domain\Search\Services\SearchIndex;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Brings the search documents of these products in line with MySQL (P4-CAT-14):
 * upsert the searchable ones, delete the rest. Idempotent — it always reads current
 * state, so duplicates and out-of-order runs converge.
 *
 * On the `index` queue, released only after the dispatching transaction commits
 * (rule 4). A Meilisearch outage throws and is retried with backoff; the nightly full
 * rebuild repairs anything that exhausts its retries.
 */
final class SyncProductSearch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Products per job: one Meilisearch batch, a few bounded queries. */
    public const int CHUNK = 200;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public readonly array $productIds;

    /** @param  list<int>  $productIds */
    public function __construct(array $productIds)
    {
        $this->productIds = array_values(array_unique(array_map('intval', $productIds)));
        $this->afterCommit();
        $this->onQueue('index');
    }

    /**
     * Dispatches one job per CHUNK ids.
     *
     * @param  iterable<int|string>  $productIds
     */
    public static function dispatchFor(iterable $productIds): void
    {
        $ids = [];
        foreach ($productIds as $id) {
            $ids[] = (int) $id;
        }

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            self::dispatch($chunk);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120, 600];
    }

    public function handle(SearchIndex $index): void
    {
        $index->sync($this->productIds);
    }
}
