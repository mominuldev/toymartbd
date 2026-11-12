<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Listeners;

use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Services\StockAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A stock level moved: open, refresh or resolve that variant's low/out-of-stock alerts
 * (StockAlerts). Queued on `reports`, so a checkout never waits on it and an alert-table
 * failure can never turn a placed order into an error — it retries on its own.
 */
final class RaiseStockAlerts implements ShouldQueue
{
    public string $queue = 'reports';

    public int $tries = 3;

    public function __construct(private readonly StockAlerts $alerts) {}

    public function handle(StockLevelChanged $event): void
    {
        $this->alerts->evaluate($event->variantId);
    }
}
