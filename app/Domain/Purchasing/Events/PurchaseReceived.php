<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stock arrived against a PO (one receipt). Held until the receipt commits.
 *
 * Deliberately NOT a ledger event: buying stock is not an expense. The cost reaches the
 * P&L as `cogs` when the goods are sold (from order_items.cost_price_snapshot, rule 7),
 * and cash leaves when the supplier is paid (SupplierPaymentRecorded). `payableAmount`
 * is what the supplier is now owed for this delivery.
 */
final readonly class PurchaseReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $purchaseId,
        public int $receiptId,
        public int $supplierId,
        public string $payableAmount,
        public bool $completed,
    ) {}
}
