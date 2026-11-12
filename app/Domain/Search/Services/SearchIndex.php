<?php

declare(strict_types=1);

namespace App\Domain\Search\Services;

use App\Domain\Catalog\Contracts\ProductSearchDocuments;
use App\Domain\Catalog\Models\Product;
use App\Domain\Search\Support\MeilisearchClients;
use App\Domain\Search\Support\Synonyms;
use Illuminate\Database\Eloquent\Collection;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use RuntimeException;

/**
 * Writes to the product index: settings, incremental sync and the full rebuild
 * (P4-CAT-14). Every method is idempotent and safe to re-run.
 */
final class SearchIndex
{
    public const int CHUNK = 500;

    /** Settings and swaps must finish before we report success. */
    private const int TASK_TIMEOUT_MS = 120_000;

    public function __construct(
        private readonly MeilisearchClients $clients,
        private readonly ProductSearchDocuments $documents,
    ) {}

    /**
     * The index settings from config/search.php, synonyms expanded.
     *
     * @return array<string, mixed>
     */
    public static function settings(): array
    {
        $settings = (array) config('search.settings', []);
        $settings['synonyms'] = Synonyms::toMeilisearch((array) config('search.synonyms', []));

        return $settings;
    }

    /**
     * Creates the index if missing and applies the settings, waiting for Meilisearch to
     * finish. Re-applying identical settings is a no-op on Meilisearch's side.
     */
    public function configure(?string $index = null): void
    {
        $client = $this->clients->forIndexing();
        $index ??= $this->clients->index();

        $this->ensureIndex($client, $index);
        $task = $client->index($index)->updateSettings(self::settings());
        $this->wait($client, $task);
    }

    /**
     * Upserts the searchable ones among `$productIds` and deletes the rest (archived,
     * unpublished, deleted, no active variant — or gone from the database). A no-op when
     * the Scout driver is not `meilisearch` (local boxes without it, the test suite).
     *
     * @param  list<int>  $productIds
     */
    public function sync(array $productIds): void
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === [] || ! $this->clients->enabled()) {
            return;
        }

        $products = Product::withTrashed()->whereIn('id', $productIds)->get();
        $this->documents->prepare($products);

        $searchable = $products->filter(fn (Product $p): bool => $this->documents->isSearchable($p))->values();
        $remove = array_values(array_diff($productIds, $searchable->map(static fn (Product $p): int => $p->id)->all()));

        if ($searchable->isNotEmpty()) {
            // Through Scout, so the engine, index name and primary key stay Scout's.
            $searchable->first()->searchableUsing()->update($searchable);
        }

        if ($remove !== []) {
            $this->clients->forIndexing()->index($this->clients->index())->deleteDocuments($remove);
        }
    }

    /**
     * Rebuilds the index from MySQL without a gap in service: documents go into a fresh
     * `<index>_rebuild`, which is then atomically swapped with the live index and dropped.
     * Products removed since the last run disappear with the old index.
     *
     * @param  (callable(int): void)|null  $progress  called with the running document count
     * @return int documents indexed
     */
    public function rebuild(?callable $progress = null): int
    {
        $client = $this->clients->forIndexing();
        $live = $this->clients->index();
        $rebuild = $live.'_rebuild';

        $this->deleteIfExists($client, $rebuild);
        $this->configure($rebuild);
        $this->ensureIndex($client, $live);

        $count = 0;
        $tasks = [];
        Product::query()->published()->orderBy('id')->chunkById(self::CHUNK, function (Collection $products) use ($client, $rebuild, &$count, &$tasks, $progress): void {
            $this->documents->prepare($products);
            $docs = [];
            foreach ($products as $product) {
                if ($this->documents->isSearchable($product)) {
                    $docs[] = $this->documents->build($product);
                }
            }
            if ($docs !== []) {
                $tasks[] = $client->index($rebuild)->addDocuments($docs, 'id')['taskUid'];
                $count += count($docs);
                if ($progress !== null) {
                    $progress($count);
                }
            }
        });

        if ($tasks !== []) {
            $client->waitForTasks($tasks, self::TASK_TIMEOUT_MS);
            $this->assertSucceeded($client, $tasks);
        }

        $this->wait($client, $client->swapIndexes([[$live, $rebuild]]));
        $this->wait($client, $client->deleteIndex($rebuild));

        return $count;
    }

    /** @param  array<string, mixed>  $task */
    private function wait(Client $client, array $task): void
    {
        $uid = $task['taskUid'] ?? null;
        if (! is_int($uid)) {
            return;
        }

        $client->waitForTask($uid, self::TASK_TIMEOUT_MS);
        $this->assertSucceeded($client, [$uid]);
    }

    /** @param  list<int>  $uids */
    private function assertSucceeded(Client $client, array $uids): void
    {
        foreach ($uids as $uid) {
            $task = $client->getTask($uid);
            if (($task['status'] ?? null) !== 'succeeded') {
                $error = is_array($task['error'] ?? null) ? (string) ($task['error']['message'] ?? '') : '';
                throw new RuntimeException("Meilisearch task {$uid} ({$task['type']}) {$task['status']}: {$error}");
            }
        }
    }

    private function ensureIndex(Client $client, string $index): void
    {
        try {
            $client->getRawIndex($index);
        } catch (ApiException $e) {
            if ($e->httpStatus !== 404) {
                throw $e;
            }
            $this->wait($client, $client->createIndex($index, ['primaryKey' => 'id']));
        }
    }

    private function deleteIfExists(Client $client, string $index): void
    {
        try {
            $client->getRawIndex($index);
        } catch (ApiException $e) {
            if ($e->httpStatus === 404) {
                return;
            }
            throw $e;
        }

        $this->wait($client, $client->deleteIndex($index));
    }
}
