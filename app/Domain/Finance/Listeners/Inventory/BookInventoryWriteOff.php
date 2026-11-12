<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Inventory;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Inventory\Events\StockWrittenOff;
use App\Support\Money;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Damage, loss, theft or expiry took stock off the shelf with no sale (P5-INV): book its
 * cost as an `inventory_write_off` (DATABASE-DESIGN §3.7) — an expense recognised in the
 * P&L (`is_recognised_revenue`: it belongs in the P&L) that moved no money
 * (`is_cash_movement = false`; the cash left when the stock was bought).
 *
 * The amount is the event's: Σ units × the location's weighted-average cost read under
 * the row lock when the adjustment applied, so Finance never re-reads Inventory and a
 * later cost change cannot rewrite it. Dated by the Dhaka day the stock moved (rule 11).
 * Queued, and idempotent through the Ledger's (source_type, source_id, type) key, so a
 * retry after a failure books it once.
 */
final class BookInventoryWriteOff implements ShouldQueue
{
    public string $queue = 'reports';

    public int $tries = 5;

    /** `financial_transactions.source_type` — the adjustment that wrote the stock off. */
    public const string SOURCE_TYPE = 'stock_adjustment';

    public function __construct(private readonly Ledger $ledger) {}

    public function handle(StockWrittenOff $event): void
    {
        $amount = Money::of($event->totalCost);
        if ($amount->isZero()) {
            return; // nothing of value left (a zero-cost variant) — no ৳0 rows in the ledger
        }

        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::InventoryWriteOff,
            direction: Direction::Out,
            amount: $amount,
            occurredOn: LedgerEntry::dhakaDateOf($event->occurredAt),
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->adjustmentId,
            isCashMovement: false,
            isRecognisedRevenue: true,
            description: "Stock write-off {$event->adjustmentNumber} ({$event->reason})",
        ));
    }
}
