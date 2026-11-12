<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Refunds;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Ordering\Events\RefundApproved;
use App\Support\DhakaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A refund was approved: `refund` out, recognised (negative revenue in the P&L), NOT cash —
 * nothing has left the business yet; {@see RecordRefundPaid} books that (rule 10). Source
 * `refund` / refund id, dated the Dhaka day of approval. Skipped when the order was never
 * delivered: its revenue was never recognised, so there is nothing to reverse (§7.6).
 */
final readonly class RecordRefundApproved
{
    public const string SOURCE = 'refund';

    public function __construct(private Ledger $ledger) {}

    public function handle(RefundApproved $event): void
    {
        if (! $event->recognised) {
            return;
        }

        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::Refund,
            direction: Direction::Out,
            amount: Money::of($event->amount),
            occurredOn: CarbonImmutable::createFromFormat('!Y-m-d', $event->occurredOn, DhakaDate::zone()) ?? throw new InvalidArgumentException("Not a Y-m-d date: {$event->occurredOn}"),
            sourceType: self::SOURCE,
            sourceId: $event->refundId,
            isCashMovement: false,
            isRecognisedRevenue: true,
            description: 'Refund approved',
        ));
    }
}
