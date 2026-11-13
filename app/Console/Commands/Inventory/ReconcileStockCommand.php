<?php

declare(strict_types=1);

namespace App\Console\Commands\Inventory;

use App\Domain\Inventory\Actions\ReconcileStockLevels;
use Illuminate\Console\Command;

/**
 * The nightly stock reconciliation (DATABASE-DESIGN §7): recompute levels from the
 * ledger, raise an alert for every drift, mail the managers about new drift. Never
 * corrects a level. Exits 1 when any drift is open, so a failing run is visible in the
 * scheduler's output and monitoring.
 */
final class ReconcileStockCommand extends Command
{
    protected $signature = 'toymart:inventory:reconcile';

    protected $description = 'Compare stock levels with the stock ledger and alert on any drift (never auto-corrects)';

    public function handle(ReconcileStockLevels $reconcile): int
    {
        $result = $reconcile->handle();

        $this->line("Checked {$result['checked']} stock level(s).");
        if (! $result['reserved_checked']) {
            $this->warn('Reserved-vs-open-orders check skipped: no OpenOrderReservations binding.');
        }
        if ($result['drift_count'] === 0) {
            $this->info('No drift.');

            return self::SUCCESS;
        }

        $this->error("{$result['drift_count']} drift(s) found ({$result['new_or_changed']} new or changed). See Inventory → Alerts.");

        return self::FAILURE;
    }
}
