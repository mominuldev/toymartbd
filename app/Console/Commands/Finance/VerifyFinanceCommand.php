<?php

declare(strict_types=1);

namespace App\Console\Commands\Finance;

use App\Domain\Finance\Services\FinanceInvariant;
use Illuminate\Console\Command;

/**
 * Invariant §7.2 — "order financials equal the ledger". Lists every drift and exits 1 if
 * there is any. Never corrects anything: drift means a code path bypassed the ledger
 * (CLAUDE.md working practice). `--rebuild` is the explicit, separate repair after the bug
 * is fixed — it rewrites the projection from the ledger, then re-verifies.
 */
final class VerifyFinanceCommand extends Command
{
    protected $signature = 'toymart:finance:verify {--rebuild : Rebuild order_financials from the ledger first (after fixing the cause)}';

    protected $description = 'Assert that every order_financials row equals its financial_transactions (never auto-corrects)';

    public function handle(FinanceInvariant $invariant, \App\Domain\Finance\Services\OrderFinancialsProjector $projector): int
    {
        if ($this->option('rebuild') === true) {
            $this->line('Rebuilt '.$projector->rebuildAll().' order_financials row(s) from the ledger.');
        }

        $drift = $invariant->drift();
        if ($drift === []) {
            $this->info('Order financials equal the ledger.');

            return self::SUCCESS;
        }

        $this->table(['order_id', 'column', 'stored', 'ledger'], array_slice($drift, 0, 200));
        $this->error(count($drift).' drift(s): order_financials disagrees with financial_transactions.');

        return self::FAILURE;
    }
}
