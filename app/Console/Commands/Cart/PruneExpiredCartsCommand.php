<?php

declare(strict_types=1);

namespace App\Console\Commands\Cart;

use App\Domain\Cart\Actions\PruneExpiredCarts;
use Illuminate\Console\Command;

/** Stale cart cleanup (SYSTEM-ARCHITECTURE §7): scheduled every ten minutes, bounded per run. */
final class PruneExpiredCartsCommand extends Command
{
    protected $signature = 'toymart:carts:prune {--limit=5000 : At most this many carts per run}';

    protected $description = 'Delete carts that expired over a week ago and never became an order (abandoned-cart history kept)';

    public function handle(PruneExpiredCarts $prune): int
    {
        $deleted = $prune->handle(max(1, (int) $this->option('limit')));
        $this->info("Deleted {$deleted} expired carts.");

        return self::SUCCESS;
    }
}
