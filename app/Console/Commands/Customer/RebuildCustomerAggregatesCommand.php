<?php

declare(strict_types=1);

namespace App\Console\Commands\Customer;

use App\Domain\Customer\Actions\RebuildCustomerAggregates;
use Illuminate\Console\Command;

/**
 * Rebuild the customer aggregate projection from orders (P4-ORD-10). Safe to re-run: every
 * chunk is a full recomputation. A drift this repairs means a code path skipped the
 * listener — find it rather than scheduling this to hide it (DATABASE-DESIGN §7).
 */
final class RebuildCustomerAggregatesCommand extends Command
{
    protected $signature = 'toymart:customers:rebuild-aggregates {--chunk=500 : Customers per transaction}';

    protected $description = 'Recompute every customer\'s orders count, total spent, AOV and first/last order from orders';

    public function handle(RebuildCustomerAggregates $rebuild): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $count = $rebuild->handle($chunk);
        $this->info("Rebuilt aggregates for {$count} customer(s).");

        return self::SUCCESS;
    }
}
