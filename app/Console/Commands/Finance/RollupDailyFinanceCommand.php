<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Domain\Finance\Services\DailySummaryRollup;
use App\Support\DhakaDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The nightly finance rollup (DATABASE-DESIGN §3.7), scheduled 01:00 Dhaka. Without options
 * it rolls up yesterday and today (Dhaka) — a late delivery or a void dated earlier gets
 * picked up by `--from`/`--to`, which rebuilds any range.
 */
final class RollupDailyFinanceCommand extends Command
{
    protected $signature = 'toymart:finance:rollup {--from= : Y-m-d, a Dhaka date} {--to= : Y-m-d, a Dhaka date}';

    protected $description = 'Rebuild daily_financial_summaries for a range of Dhaka dates (default: yesterday and today)';

    public function handle(DailySummaryRollup $rollup): int
    {
        $today = DhakaDate::today();
        $from = $this->option('from');
        $to = $this->option('to');
        $from = is_string($from) && $from !== '' ? $from : (CarbonImmutable::createFromFormat('!Y-m-d', $today)?->subDay()->toDateString() ?? $today);
        $to = is_string($to) && $to !== '' ? $to : $today;

        try {
            $count = $rollup->run($from, $to);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        $this->info("Rolled up {$count} day(s), {$from} to {$to}.");

        return self::SUCCESS;
    }
}
