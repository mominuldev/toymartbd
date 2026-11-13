<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Courier;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Fulfilment\Events\ShipmentBooked;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * ShipmentBooked → `courier_charge` (DATABASE-DESIGN §4.2): out, recognised (it is a cost
 * of the order in the P&L) and cash (§4.2 — the courier nets it off the remittance), dated
 * the Dhaka day of the booking (rule 11), source `shipment` (mapped to its order in
 * OrderLedgerSources). It is the one entry §7 invariant 6 allows before delivery, and it
 * stays as a sunk loss on a courier return (§4.4).
 *
 * Queued after the booking commits; idempotent through the ledger's
 * (source_type, source_id, type) key. A provider that reported no charge writes nothing.
 */
final class RecordCourierCharge implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly Ledger $ledger) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(ShipmentBooked $event): void
    {
        if ($event->charge === null) {
            return;
        }
        $charge = Money::of($event->charge);
        if ($charge->isZero() || $charge->isNegative()) {
            return;
        }

        $this->ledger->record(new LedgerEntry(
            type: LedgerEntryType::CourierCharge,
            direction: Direction::Out,
            amount: $charge,
            occurredOn: LedgerEntry::dhakaDateOf(CarbonImmutable::parse($event->bookedAt)),
            sourceType: 'shipment',
            sourceId: $event->shipmentId,
            isCashMovement: true,
            isRecognisedRevenue: true,
            description: "Courier charge {$event->providerCode} {$event->shipmentNumber}",
        ));
    }
}
