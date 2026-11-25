<?php

declare(strict_types=1);

namespace App\Console\Commands\Catalog;

use App\Domain\Catalog\Actions\RebuildProductCopurchases;
use Illuminate\Console\Command;

/**
 * Nightly rebuild of the co-purchase projection behind "Frequently bought together"
 * (P10, PRD X-04). Scheduled at 03:30 Dhaka in routes/console.php; safe to run by hand.
 */
final class RebuildProductCopurchasesCommand extends Command
{
    protected $signature = 'toymart:catalog:copurchases';

    protected $description = 'Rebuild product_copurchases from the last 180 days of non-cancelled orders';

    public function handle(RebuildProductCopurchases $rebuild): int
    {
        $result = $rebuild->handle();

        $this->info("Rebuilt {$result['pairs']} co-purchase pair(s) from orders since {$result['since']->toDateString()}.");

        return self::SUCCESS;
    }
}
