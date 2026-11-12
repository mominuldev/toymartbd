<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Refunds;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Ordering\Events\RefundPaid;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The refund money left the business: `refund` out, cash, NOT recognised (the P&L already
 * carries it from approval — rule 10 keeps revenue and cash apart). Source `refund_payout` /
 * refund id — a different key from the recognised entry, and the Ledger's
 * (source, id, type) key makes a second "paid" a no-op.
 */
final readonly class RecordRefundPaid
{
    public const string SOURCE = 'refund_payout';

    public function __construct(private Ledger $ledger) {}

    public function handle(RefundPaid $event): void
    {
        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::Refund,
            direction: Direction::Out,
            amount: Money::of($event->amount),
            occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', $event->occurredOn, DhakaDate::zone()) ?? throw new InvalidArgumentException("Not a Y-m-d date: {$event->occurredOn}"),
            sourceType: self::SOURCE,
            sourceId: $event->refundId,
            isCashMovement: true,
            isRecognisedRevenue: false,
            description: "Refund paid ({$event->method})",
        ));
    }
}
