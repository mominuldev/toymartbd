<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Listeners;

use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Services\StockOverview;
use Throwable;

/**
 * A stock level moved, so the Inventory nav badge's cached low-stock count may be wrong.
 * Synchronous and cheap (cache forgets); StockLevelChanged is dispatched after commit.
 *
 * Never lets a cache failure escape: this runs inside checkout, stock adjustments and
 * order cancellation, all of which have already committed. A Redis blip must not turn a
 * placed order into a 500 for the customer; the worst case is a badge that stays stale
 * until its cache entry expires (StockOverview caches it for at most 5 minutes).
 */
final class ForgetLowStockCount
{
    public function handle(StockLevelChanged $event): void
    {
        try {
            StockOverview::forgetLowCounts();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
