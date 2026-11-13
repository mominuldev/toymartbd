<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Courier;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Fulfilment\Events\ShipmentDelivered;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ShipmentDelivered → `courier_cod_fee` (DATABASE-DESIGN §4.3): out, recognised, cash,
 * dated the Dhaka day of delivery, source `shipment`. Revenue itself is NOT written here —
 * that stays on OrderDelivered (RecognizeRevenueOnDelivery). Queued after commit;
 * idempotent through the ledger key. A prepaid parcel (fee 0) writes nothing.
 */
final class RecordCourierCodFee implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly Ledger $ledger) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(ShipmentDelivered $event): void
    {
        $fee = Money::of($event->codFee);
        if ($fee->isZero() || $fee->isNegative()) {
            return;
        }

        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::CourierCodFee,
            direction: Direction::Out,
            amount: $fee,
            occurredOn: LedgerEntry::dhakaDateOf(CarbonImmutable::parse($event->deliveredAt)),
            sourceType: 'shipment',
            sourceId: $event->shipmentId,
            isCashMovement: true,
            isRecognisedRevenue: true,
            description: "COD fee {$event->providerCode} {$event->shipmentNumber}",
        ));
    }
}
