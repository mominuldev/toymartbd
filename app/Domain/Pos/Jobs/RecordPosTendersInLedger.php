<?php

declare(strict_types=1);

namespace App\Domain\Pos\Jobs;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Models\PosDueCollectionAllocation;
use App\Domain\Pos\Models\PosSale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cash side of a counter sale (rule 10): every paid tender is money that moved — into the
 * drawer (cash) or the shop's bKash / Nagad / card-acquirer account — so it is booked as a
 * `pos_receipt` entry: `is_cash_movement = true`, `is_recognised_revenue = false`, dated the
 * Dhaka day it was paid (rule 11), sourced on the payment (`payment` is an order-scoped
 * source, so it is part of the order's cash, never its P&L). Revenue is recognised separately
 * by Finance on OrderDelivered; the two are never one entry.
 *
 * Dispatched after the sale commits. Idempotent: the ledger skips an existing
 * (payment, id, type), so a retried job writes nothing twice.
 *
 * `LedgerEntryType::PosReceipt` is its own type rather than `cod_received` (that would reduce
 * COD receivables) or `other_income` (a P&L type: it would count cash as profit).
 */
final class RecordPosTendersInLedger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const string TYPE = 'pos_receipt';

    public const string SOURCE = 'payment';

    /** @param  string  $type  the ledger type the tenders are booked as (carried, so a queued job books what it was dispatched with) */
    public function __construct(public readonly int $saleId, public readonly string $type = self::TYPE)
    {
        $this->onQueue('critical');
    }

    public function handle(Ledger $ledger): void
    {
        $type = LedgerEntryType::tryFrom($this->type);
        $sale = PosSale::query()->with('order')->find($this->saleId);
        if ($type === null || $sale === null) {
            return;
        }

        $entries = [];
        // The sale's own tenders: due collected later on the same order is booked by
        // RecordPosDueCollectionInLedger (same type and key, so even a race books it once).
        $payments = Payment::query()->where('order_id', $sale->order_id)
            ->whereNotIn('id', PosDueCollectionAllocation::query()->select('payment_id'))
            ->where('status', PaymentStatus::Paid->value)->orderBy('id')->get();
        foreach ($payments as $payment) {
            $entries[] = new LedgerEntry(
                type: $type,
                direction: Direction::In,
                amount: $payment->amount,
                occurredOn: LedgerEntry::dhakaDateOf($payment->paid_at ?? $sale->created_at ?? now()),
                sourceType: self::SOURCE,
                sourceId: $payment->id,
                isCashMovement: true,
                isRecognisedRevenue: false,
                description: "POS {$payment->method->value} · order {$sale->order->number}",
            );
        }

        if ($entries !== []) {
            $ledger->record(...$entries);
        }
    }
}
