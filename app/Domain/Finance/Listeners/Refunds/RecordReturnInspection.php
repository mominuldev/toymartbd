<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Refunds;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Ordering\Events\ReturnInspected;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Goods came back (DATABASE-DESIGN §4.7). Source `order_return` / return id:
 *
 *   cogs                 in   every returned unit at its cost_price_snapshot (rule 7) — the
 *                             sale's COGS no longer applies to goods we hold again
 *   inventory_write_off  out  the damaged units at the same snapshot cost — they came back
 *                             but cannot be sold (the P&L's "damaged stock written off")
 *
 * Net effect: only resellable units improve profit; a damaged unit costs exactly what it
 * did before, but is shown as a write-off rather than a sale. Both recognised, neither
 * cash (rule 10). Skipped for an order never delivered: no COGS was booked (§7.6).
 * Idempotent through the Ledger's (source, id, type) key.
 */
final readonly class RecordReturnInspection
{
    public const string SOURCE = 'order_return';

    public function __construct(private Ledger $ledger) {}

    public function handle(ReturnInspected $event): void
    {
        if (! $event->recognised) {
            return;
        }

        $on = CarbonImmutable::createFromFormat('!Y-m-d', $event->occurredOn, DhakaDate::zone()) ?? throw new InvalidArgumentException("Not a Y-m-d date: {$event->occurredOn}");
        $entry = static fn (LedgerEntryType $type, Direction $direction, Money $amount, string $what): LedgerEntry => new LedgerEntry(
            type: $type,
            direction: $direction,
            amount: $amount,
            occurredOn: $on,
            sourceType: self::SOURCE,
            sourceId: $event->returnId,
            isCashMovement: false,
            isRecognisedRevenue: true,
            description: $what,
        );

        $entries = [];
        $returned = Money::of($event->returnedCost);
        if (! $returned->isZero()) {
            $entries[] = $entry(LedgerEntryType::Cogs, Direction::In, $returned, 'COGS reversed — goods returned');
        }
        $damaged = Money::of($event->damagedCost);
        if (! $damaged->isZero()) {
            $entries[] = $entry(LedgerEntryType::InventoryWriteOff, Direction::Out, $damaged, 'Returned damaged — written off');
        }

        if ($entries !== []) {
            $this->ledger->record(...$entries);
        }
    }
}
