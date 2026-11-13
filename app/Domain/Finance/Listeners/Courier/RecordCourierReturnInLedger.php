<?php

declare(strict_types=1);

namespace App\Domain\Finance\Listeners\Courier;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Services\OrderLedgerSources;
use App\Domain\Fulfilment\Events\ShipmentReturned;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;

/**
 * ShipmentReturned → the courier-return ledger rows (DATABASE-DESIGN §4.4), dated the Dhaka
 * day of the return:
 *
 *   courier_return_charge  out, recognised, cash      source `shipment`
 *   and ONLY if the order's revenue had been recognised (it was delivered first):
 *   sale_revenue / shipping_revenue out, cogs / discount_given in — each the exact mirror
 *   of the recognised `order` entry, recognised, not cash, source `order_reversal`.
 *
 * The outbound `courier_charge` is left alone: it is a sunk loss. A parcel returned before
 * delivery had nothing recognised, so only the return charge is written. Queued after
 * commit; idempotent through the ledger key (one reversal per order). The entries go in as
 * one multi-row INSERT through the Ledger, so no transaction is needed here (rule 3).
 */
final class RecordCourierReturnInLedger implements ShouldQueue
{
    public bool $afterCommit = true;

    private const array REVERSIBLE = [
        LedgerEntryType::SaleRevenue, LedgerEntryType::Cogs, LedgerEntryType::DiscountGiven, LedgerEntryType::ShippingRevenue,
    ];

    public function __construct(private readonly Ledger $ledger) {}

    public function viaQueue(): string
    {
        return 'critical';
    }

    public function handle(ShipmentReturned $event): void
    {
        $on = LedgerEntry::dhakaDateOf(CarbonImmutable::parse($event->returnedAt));
        $entries = [];

        $charge = Money::of($event->returnCharge);
        if (! $charge->isZero() && ! $charge->isNegative()) {
            $entries[] = new LedgerEntry(
                type: LedgerEntryType::CourierReturnCharge,
                direction: Direction::Out,
                amount: $charge,
                occurredOn: $on,
                sourceType: 'shipment',
                sourceId: $event->shipmentId,
                isCashMovement: true,
                isRecognisedRevenue: true,
                description: "Courier return charge {$event->providerCode} {$event->shipmentNumber}",
            );
        }

        $recognised = DB::table('financial_transactions')
            ->where('source_type', OrderLedgerSources::ORDER)
            ->where('source_id', $event->orderId)
            ->where('is_recognised_revenue', true)
            ->whereIn('type', array_map(static fn (LedgerEntryType $t): string => $t->value, self::REVERSIBLE))
            ->get(['type', 'direction', 'amount']);

        foreach ($recognised as $row) {
            $entries[] = new LedgerEntry(
                type: LedgerEntryType::from((string) $row->type),
                direction: Direction::from((string) $row->direction) === Direction::In ? Direction::Out : Direction::In,
                amount: Money::of((string) $row->amount),
                occurredOn: $on,
                sourceType: OrderLedgerSources::ORDER_REVERSAL,
                sourceId: $event->orderId,
                isCashMovement: false,
                isRecognisedRevenue: true,
                description: "Courier return {$event->shipmentNumber}: reversal",
            );
        }

        $this->ledger->record(...$entries);
    }
}
