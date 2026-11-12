<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Domain\Search\Services\SearchIndex;
use App\Domain\Search\Support\MeilisearchClients;
use Illuminate\Console\Command;

/**
 * Full rebuild of the product index from MySQL, with no gap in service (build a fresh
 * index, then swap). Scheduled nightly (routes/console.php); safe to run by hand at any
 * time — e.g. after the first deploy, or when the index-lag check fires.
 */
final class ReindexSearchCommand extends Command
{
    protected $signature = 'toymart:search:reindex';

    protected $description = 'Rebuild the product search index from the database and swap it in';

    public function handle(SearchIndex $index, MeilisearchClients $clients): int
    {
        if (! $clients->enabled()) {
            $this->error('SCOUT_DRIVER is not "meilisearch"; nothing to reindex.');

            return self::FAILURE;
        }

        $started = microtime(true);
        $count = $index->rebuild(fn (int $n) => $this->output->isVerbose() ? $this->line("… {$n} documents") : null);
        $this->info(sprintf('Indexed %d products into "%s" in %.1fs.', $count, $clients->index(), microtime(true) - $started));

        return self::SUCCESS;
    }
}
