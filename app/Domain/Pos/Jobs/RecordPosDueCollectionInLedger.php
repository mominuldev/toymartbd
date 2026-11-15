<?php

declare(strict_types=1);

namespace App\Domain\Pos\Jobs;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Payment\Enums\PaymentStatus;
use App\Domain\Payment\Models\Payment;
use App\Domain\Pos\Models\PosDueCollection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Cash side of a due collection — booked exactly like a counter sale's tenders
 * ({@see RecordPosTendersInLedger}): each paid payment the collection wrote is a `pos_receipt`
 * entry, `is_cash_movement = true`, `is_recognised_revenue = false` (rule 10), dated the Dhaka
 * day it was paid (rule 11), sourced on the payment. The revenue was recognised when the order
 * was handed over; a due collection never touches the P&L. Never `cod_received` (that would
 * reduce COD receivables).
 *
 * Dispatched after the collection commits. Idempotent per (payment, id, type).
 */
final class RecordPosDueCollectionInLedger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $collectionId)
    {
        $this->onQueue('critical');
    }

    public function handle(Ledger $ledger): void
    {
        $type = LedgerEntryType::PosReceipt;
        $collection = PosDueCollection::query()->with('allocations.order')->find($this->collectionId);
        if ($collection === null) {
            return;
        }

        $numbers = $collection->allocations->mapWithKeys(static fn ($a): array => [$a->payment_id => $a->order->number])->all();
        $payments = Payment::query()->whereKey(array_keys($numbers))
            ->where('status', PaymentStatus::Paid->value)->orderBy('id')->get();

        $entries = [];
        foreach ($payments as $payment) {
            $entries[] = new LedgerEntry(
                type: $type,
                direction: Direction::In,
                amount: $payment->amount,
                occurredOn: LedgerEntry::dhakaDateOf($payment->paid_at ?? $collection->created_at ?? now()),
                sourceType: RecordPosTendersInLedger::SOURCE,
                sourceId: $payment->id,
                isCashMovement: true,
                isRecognisedRevenue: false,
                description: "POS due {$payment->method->value} · order {$numbers[$payment->id]}",
            );
        }

        if ($entries !== []) {
            $ledger->record(...$entries);
        }
    }
}
