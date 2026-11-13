<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Content\Jobs\RevalidateStorefrontTags;
use App\Domain\Customer\Jobs\SendLoginOtp;
use App\Domain\Inventory\Events\StockLevelChanged;
use App\Domain\Inventory\Services\StockOverview;
use App\Domain\Notification\Jobs\SendCartRecoveryMessage;
use App\Domain\Notification\Jobs\SendNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\BusFake;

/**
 * The guard rails every transactional demo seeder of the orders agent runs inside, so
 * seeding history through the real Actions never reaches the outside world and never
 * leaves the process in a strange state:
 *
 *  - **Queue `sync`.** The ledger, customer aggregates and risk profiles are written by
 *    queued listeners (RecognizeRevenueOnDelivery, RefreshAggregatesOnOrderChange …). On a
 *    developer's redis queue they would run later, in a worker, at the wrong date — so the
 *    demo history runs them in-process, at the frozen clock of the step that raised them.
 *  - **Outbound jobs recorded, never run**: SMS / WhatsApp / e-mail sends, the sign-in
 *    code SMS, the cart-recovery SMS and storefront revalidation. Their `notification_logs`
 *    rows are written as usual (the admin's message log has history) but nothing is sent —
 *    with a real SMS_DRIVER configured, a seed must not text invented numbers.
 *  - **StockLevelChanged faked** (as DemoInventorySeeder does): its listeners only purge
 *    storefront caches, reindex search (HTTP to Meilisearch) and raise inventory alerts —
 *    no projection or ledger depends on it. The low-stock count cache is forgotten at the end.
 *  - The clock is frozen per step with {@see self::at()} and ALWAYS unfrozen, the auth
 *    guards forgotten, and the bus restored, in `finally`.
 */
final class DemoOrderSandbox
{
    /** @var list<class-string> jobs that would talk to the outside world */
    public const array RECORDED_JOBS = [
        SendNotification::class,
        SendLoginOtp::class,
        SendCartRecoveryMessage::class,
        RevalidateStorefrontTags::class,
    ];

    /**
     * @template T
     *
     * @param  callable(BusFake): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        $queue = config('queue.default');
        $bus = Bus::getFacadeRoot();
        config(['queue.default' => 'sync']);
        $fake = Bus::fake(self::RECORDED_JOBS);

        try {
            return Event::fakeFor(static fn (): mixed => $callback($fake), [StockLevelChanged::class]);
        } finally {
            self::at(null);
            Auth::forgetGuards();
            Bus::swap($bus);
            config(['queue.default' => $queue]);
            StockOverview::forgetLowCounts();
        }
    }

    /** Freeze both clocks at this instant, or unfreeze them with null. */
    public static function at(?CarbonImmutable $moment): void
    {
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
