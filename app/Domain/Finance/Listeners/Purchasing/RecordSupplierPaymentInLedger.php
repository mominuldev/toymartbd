<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Purchasing;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Purchasing\Events\SupplierPaymentRecorded;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A supplier payment becomes one `supplier_payment` ledger entry: direction out,
 * `is_cash_movement = 1`, `is_recognised_revenue = 0` (rule 10). It moves the cash
 * position, never the P&L.
 *
 * Why the P&L never sees purchasing: buying stock converts cash into inventory, an
 * asset. The cost is recognised as `cogs` when the goods are sold (from
 * order_items.cost_price_snapshot, rule 7), so expensing the purchase as well would
 * count the same taka twice. Receipts therefore write no ledger entry at all.
 *
 * Runs after the payment commits (the event is ShouldDispatchAfterCommit). The ledger
 * is idempotent on (source_type, source_id, type), so a replayed event writes nothing.
 */
final readonly class RecordSupplierPaymentInLedger
{
    /** `financial_transactions.source_type` — mirrors SupplierPayment::SOURCE_TYPE without importing Purchasing. */
    public const string SOURCE_TYPE = 'supplier_payment';

    public function __construct(private Ledger $ledger) {}

    public function handle(SupplierPaymentRecorded $event): void
    {
        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::SupplierPayment,
            direction: Direction::Out,
            amount: Money::of($event->amount),
            occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', $event->paidOn, DhakaDate::zone()) ?: throw new InvalidArgumentException('Unparseable payment date.'),
            sourceType: self::SOURCE_TYPE,
            sourceId: $event->paymentId,
            isCashMovement: true,
            isRecognisedRevenue: false,
            description: mb_substr('Supplier payment · '.$event->supplierName, 0, 255),
        ));
    }
}
