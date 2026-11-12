<?php

declare(strict_types=1);

namespace App\Domain\Finance\Contracts;

use App\Domain\Finance\DTOs\LedgerEntry;

/**
 * The only write path into `financial_transactions` (rule 8: append-only).
 *
 * Records every entry or none (one transaction). Idempotent per
 * `(source_type, source_id, type)`: an entry whose key already exists is skipped
 * silently, so a replayed event or a retried job writes nothing twice. Corrections are
 * new reversing entries on a new source, never an update.
 *
 * Call it from a Finance listener after the originating transaction has committed —
 * never from inside another domain's Action.
 */
interface Ledger
{
    public function record(LedgerEntry ...$entries): void;
}
