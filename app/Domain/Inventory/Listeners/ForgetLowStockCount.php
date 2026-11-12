<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Listeners;

use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Services\StockOverview;

/**
 * A stock level moved, so the Inventory nav badge's cached low-stock count may be wrong.
 * Synchronous and cheap (cache forgets); StockLevelChanged is dispatched after commit.
 */
final class ForgetLowStockCount
{
    public function handle(StockLevelChanged $event): void
    {
        StockOverview::forgetLowCounts();
    }
}
