<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Settlement;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Events\CourierSettlementReconciled;
use App\Domain\Finance\Models\CourierSettlement;
use App\Domain\Finance\Models\CourierSettlementItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Settlement confirmed → the ledger (§4.5, rule 10), for each booked line, dated the Dhaka day
 * the remittance arrived:
 *
 *  - `cod_received` — the GROSS amount the courier collected (owner decision 2026-11-13), in,
 *    cash, NOT revenue; source `courier_settlement_item` (one per line → idempotent per line);
 *  - `courier_charge` / `courier_cod_fee` — out, recognised, cash — only where not already
 *    booked: written under the SAME key the courier listeners use (`shipment`, shipment id),
 *    so whichever of booking/delivery/settlement comes first wins and the other writes nothing.
 *
 * Return charges are the courier-return listener's (ShipmentReturned). Queued on `critical`;
 * a replay writes nothing.
 */
final readonly class BookCourierSettlement implements ShouldQueue
{
    public const string SOURCE = 'courier_settlement_item';

    public function __construct(private Ledger $ledger) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(CourierSettlementReconciled $event): void
    {
        $settlement = CourierSettlement::query()->find($event->settlementId);
        if ($settlement === null) {
            return;
        }
        $on = CarbonImmutable::parse($settlement->received_at->toDateString(), 'Asia/Dhaka');

        $entries = [];
        $items = $settlement->items()->whereNotNull('booked_at')->whereNotNull('shipment_id')->orderBy('id')->get();
        foreach ($items as $item) {
            /** @var CourierSettlementItem $item */
            $label = "Courier settlement {$settlement->reference}, consignment {$item->consignment_id}";
            if ($item->cod_amount->greaterThan(\App\Support\Money::zero())) {
                $entries[] = new LedgerEntry(LedgerEntryType::CodReceived, Direction::In, $item->cod_amount, $on, self::SOURCE, $item->id, isCashMovement: true, isRecognisedRevenue: false, description: $label);
            }
            if ($item->charges->greaterThan(\App\Support\Money::zero())) {
                $entries[] = new LedgerEntry(LedgerEntryType::CourierCharge, Direction::Out, $item->charges, $on, 'shipment', (int) $item->shipment_id, isCashMovement: true, isRecognisedRevenue: true, description: $label);
            }
            if ($item->cod_fee->greaterThan(\App\Support\Money::zero())) {
                $entries[] = new LedgerEntry(LedgerEntryType::CourierCodFee, Direction::Out, $item->cod_fee, $on, 'shipment', (int) $item->shipment_id, isCashMovement: true, isRecognisedRevenue: true, description: $label);
            }
        }

        $this->ledger->record(...$entries);
    }
}
