<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use App\Domain\Inventory\Events\InventoryDigestCompiled;
use App\Domain\Inventory\Services\InventoryDigest;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The managers' daily low-stock digest (PRD I-09), scheduled 09:00 Asia/Dhaka. Reports the
 * alerts raised on the PREVIOUS Dhaka day (or `--date`) and everything still open now.
 * Sends nothing on a day with nothing to report.
 */
final class SendStockDigestCommand extends Command
{
    protected $signature = 'toymart:inventory:digest {--date= : Dhaka date to report, Y-m-d (default: yesterday in Dhaka)}';

    protected $description = 'Mail the daily low-stock / out-of-stock / drift digest to managers';

    public function handle(InventoryDigest $digest): int
    {
        $option = $this->option('date');
        $date = is_string($option) && $option !== ''
            ? $option
            : CarbonImmutable::now(DhakaDate::zone())->subDay()->toDateString();
        DhakaDate::utcBounds($date); // validates Y-m-d

        $compiled = $digest->compile($date);
        if ($compiled['empty']) {
            $this->info("Nothing to report for {$date}.");

            return self::SUCCESS;
        }

        InventoryDigestCompiled::dispatch($date, $compiled);
        $this->info("Stock digest for {$date} sent.");

        return self::SUCCESS;
    }
}
