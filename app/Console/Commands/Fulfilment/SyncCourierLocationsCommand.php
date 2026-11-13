<?php

declare(strict_types=1);

namespace App\Console\Commands\Fulfilment;

use App\Domain\Fulfilment\Actions\ImportCourierLocations;
use App\Domain\Fulfilment\Jobs\SyncCourierLocations;
use App\Domain\Fulfilment\Services\CourierRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Mirror a courier's city/zone/area tree into `courier_locations` (DATABASE-DESIGN §3.6).
 * Runs inline by default and prints the counts; `--queue` dispatches it to the `courier`
 * queue instead. Scheduled nightly. Maps nothing — mapping is a human decision (rule 32).
 */
final class SyncCourierLocationsCommand extends Command
{
    protected $signature = 'toymart:couriers:sync-locations {provider=pathao : courier_providers.code} {--queue : Dispatch to the courier queue instead of running now}';

    protected $description = "Mirror a courier's own location tree (cities, zones, areas) for manual mapping";

    public function handle(CourierRegistry $registry, ImportCourierLocations $import): int
    {
        $code = (string) $this->argument('provider');

        if ($this->option('queue')) {
            SyncCourierLocations::dispatch($code);
            $this->info("Location sync for {$code} queued.");

            return self::SUCCESS;
        }

        try {
            $report = (new SyncCourierLocations($code))->handle($registry, $import);
        } catch (Throwable $e) {
            // The class and message only: neither carries a token or credential (PathaoException).
            $this->error("Location sync for {$code} failed: ".$e::class.' — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s: %d cities, %d zones, %d areas (%d new rows, %d deactivated, %d mapped rows now inactive).',
            $code, $report->cities, $report->zones, $report->areas, $report->created, $report->deactivated, $report->mappedInactive,
        ));

        return self::SUCCESS;
    }
}
