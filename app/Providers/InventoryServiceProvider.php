<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Listeners\Inventory\BookInventoryWriteOff;
use App\Domain\Inventory\Events\InventoryDigestCompiled;
use App\Domain\Inventory\Events\StockDriftDetected;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Events\StockWrittenOff;
use App\Domain\Inventory\Listeners\RaiseStockAlerts;
use App\Domain\Inventory\Models\InventoryAlert;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Domain\Notification\Listeners\MailInventoryAlerts;
use App\Policies\StockPolicy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Inventory operations wiring (P5-INV): policies for the models it added, and the event →
 * listener map for stock alerts, write-offs (Finance books them) and the staff mail
 * (Notification sends it). Its own provider so this domain does not contend for the
 * shared AppServiceProvider / AuthServiceProvider maps.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Every model has a Policy (rule 21).
        Gate::policy(StockTransfer::class, StockPolicy::class);
        Gate::policy(StockTransferItem::class, StockPolicy::class);
        Gate::policy(InventoryAlert::class, StockPolicy::class);

        Event::listen(StockLevelChanged::class, RaiseStockAlerts::class);
        Event::listen(StockWrittenOff::class, BookInventoryWriteOff::class);
        Event::listen(StockDriftDetected::class, [MailInventoryAlerts::class, 'onDrift']);
        Event::listen(InventoryDigestCompiled::class, [MailInventoryAlerts::class, 'onDigest']);
    }
}
