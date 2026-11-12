<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Domain\Search\Services\IndexLagCheck;
use App\Domain\Search\Support\MeilisearchClients;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The index-lag alert (P4-CAT-14). Scheduled every five minutes. On lag it logs at
 * `error` — which reaches Sentry wherever SENTRY_LARAVEL_DSN is set — exits non-zero, and
 * the health endpoint reports `checks.search_index: fail` until a run passes again.
 * Fix: `php artisan toymart:search:reindex`, then look at the `index` queue worker.
 */
final class CheckSearchIndexLagCommand extends Command
{
    protected $signature = 'toymart:search:lag-check';

    protected $description = 'Alert when the product search index lags the database';

    public function handle(IndexLagCheck $check, MeilisearchClients $clients): int
    {
        if (! $clients->enabled()) {
            $this->line('SCOUT_DRIVER is not "meilisearch"; skipped.');

            return self::SUCCESS;
        }

        $result = $check->run();
        $summary = [
            'expected' => $result['expected'],
            'indexed' => $result['indexed'],
            'stale' => count($result['stale']),
            'missing' => count($result['missing']),
            'stale_ids' => array_slice($result['stale'], 0, 20),
            'missing_ids' => array_slice($result['missing'], 0, 20),
            'error' => $result['error'],
        ];

        if ($result['ok']) {
            $this->info("Search index in sync ({$result['indexed']} documents).");

            return self::SUCCESS;
        }

        Log::error('Search index lags the database.', $summary);
        $this->error('Search index lags the database: '.json_encode($summary, JSON_UNESCAPED_SLASHES));

        return self::FAILURE;
    }
}
