<?php

declare(strict_types=1);

namespace App\Domain\Search\Support;

use GuzzleHttp\Client as Guzzle;
use Meilisearch\Client;

/**
 * Meilisearch clients with explicit timeouts. Scout builds its client with none, so a
 * hung Meilisearch would hold a storefront render (or a queue worker) indefinitely.
 *
 * - `forSearch()`: short timeouts; the search API falls back to the database past them.
 * - `forIndexing()`: generous timeouts for the queue worker and artisan commands. Also
 *   bound as Scout's `Meilisearch\Client` (SearchServiceProvider).
 */
final class MeilisearchClients
{
    private ?Client $search = null;

    private ?Client $indexing = null;

    public function enabled(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }

    public function forSearch(): Client
    {
        return $this->search ??= $this->make(
            (float) config('search.timeout_seconds', 2.5),
            (float) config('search.connect_timeout_seconds', 1.0),
        );
    }

    public function forIndexing(): Client
    {
        return $this->indexing ??= $this->make((float) config('search.indexing_timeout_seconds', 30), 5.0);
    }

    /** The index name with the Scout prefix — the same as Product::searchableAs(). */
    public function index(): string
    {
        return config('scout.prefix').config('search.index', 'products');
    }

    private function make(float $timeout, float $connectTimeout): Client
    {
        $host = config('scout.meilisearch.host');
        $key = config('scout.meilisearch.key');

        return new Client(
            is_string($host) && $host !== '' ? $host : 'http://127.0.0.1:7700',
            is_string($key) && $key !== '' ? $key : null,
            new Guzzle(['timeout' => $timeout, 'connect_timeout' => $connectTimeout, 'http_errors' => false]),
            clientAgents: ['ToyMart search'],
        );
    }
}
