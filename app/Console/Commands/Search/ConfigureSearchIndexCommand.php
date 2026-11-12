<?php

declare(strict_types=1);

namespace App\Console\Commands\Search;

use App\Domain\Search\Services\SearchIndex;
use App\Domain\Search\Support\MeilisearchClients;
use Illuminate\Console\Command;

/**
 * Applies config/search.php (attributes, ranking, typo tolerance, synonyms) to the product
 * index. Idempotent: run it on every deploy and after editing the synonym list.
 */
final class ConfigureSearchIndexCommand extends Command
{
    protected $signature = 'toymart:search:configure';

    protected $description = 'Create the product search index if needed and apply its settings and synonyms';

    public function handle(SearchIndex $index, MeilisearchClients $clients): int
    {
        if (! $clients->enabled()) {
            $this->error('SCOUT_DRIVER is not "meilisearch"; nothing to configure.');

            return self::FAILURE;
        }

        $index->configure();
        $this->info("Search index \"{$clients->index()}\" configured (".count(SearchIndex::settings()['synonyms']).' synonym terms).');

        return self::SUCCESS;
    }
}
