<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\Listeners\Orders\RecognizeRevenueOnDelivery;
use App\Domain\Finance\Services\EloquentLedger;
use App\Domain\Finance\Services\OrderLedgerSources;
use App\Domain\Ordering\Events\OrderDelivered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Finance wiring (Phase 5): the Ledger contract every domain's Finance listener writes
 * through, and the listeners that turn other domains' events into ledger entries (rule 2).
 * Listeners for another area live under app/Domain/Finance/Listeners/<Area>/ and are
 * registered here.
 */
final class FinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OrderLedgerSources::class);
        $this->app->bind(Ledger::class, EloquentLedger::class);
    }

    public function boot(): void
    {
        // Orders: revenue recognised on delivery (§4.3). Cancel before delivery: nothing to do.
        Event::listen(OrderDelivered::class, RecognizeRevenueOnDelivery::class);
    }
}
