<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Jobs;

use App\Domain\Fulfilment\Actions\ImportCourierLocations;
use App\Domain\Fulfilment\Locations\LocationSyncReport;
use App\Domain\Fulfilment\Locations\ProvidesCourierLocations;
use App\Domain\Fulfilment\Models\CourierProvider;
use App\Domain\Fulfilment\Services\CourierRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Pull a courier's location tree and mirror it (nightly, and on demand from the admin).
 * Fetch first, over HTTP, outside any transaction (rule 4); then one import transaction.
 * One sync per provider at a time. The last report is cached for the admin screen.
 */
final class SyncCourierLocations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $providerCode)
    {
        $this->onQueue('courier');
    }

    public function uniqueId(): string
    {
        return $this->providerCode;
    }

    public static function lastReportKey(string $providerCode): string
    {
        return 'couriers:locations:last-sync:'.$providerCode;
    }

    public function handle(CourierRegistry $registry, ImportCourierLocations $import): LocationSyncReport
    {
        $provider = CourierProvider::query()->where('code', $this->providerCode)->firstOrFail();
        $driver = $registry->for($this->providerCode);
        if (! $driver instanceof ProvidesCourierLocations) {
            throw new InvalidArgumentException("Courier {$this->providerCode} has no location tree to sync.");
        }

        $report = $import->handle($provider, $driver->fetchLocations());

        Cache::forever(self::lastReportKey($this->providerCode), [...$report->toArray(), 'finished_at' => now()->toIso8601String()]);
        Log::info('courier.locations.synced', ['provider' => $this->providerCode, ...$report->toArray()]);

        return $report;
    }
}
