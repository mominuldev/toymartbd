<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Contracts\Ledger;
use App\Domain\Finance\DTOs\LedgerEntry;
use App\Domain\Finance\Enums\Direction;
use App\Domain\Finance\Enums\LedgerEntryType;
use App\Domain\Finance\Exceptions\CodReceiptRefused;
use App\Domain\Finance\Models\ManualCodReceipt;
use App\Domain\Finance\Services\Receivables;
use App\Domain\Ordering\Models\Order;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Record COD received" (P5; stands in for Phase 6 settlement matching, §4.5). Writes one
 * `cod_received` entry — **cash, not revenue** (rule 10) — so receivables = recognised −
 * received. Refused when the order is not COD, not yet delivered (nothing recognised), or
 * the amount exceeds what is outstanding. The order row is locked so two clerks recording
 * the same remittance cannot both pass the outstanding check.
 *
 * The amount is the GROSS sum the courier collected from the customer; the courier's COD
 * fee is a separate `courier_cod_fee` entry, never netted here (owner decision 2026-11-13).
 */
final readonly class RecordCodReceived
{
    public function __construct(private Ledger $ledger, private Receivables $receivables) {}

    /** @throws CodReceiptRefused */
    public function handle(Order $order, Money $amount, CarbonImmutable $receivedOn, ?string $reference, ?string $note, ?int $userId): ManualCodReceipt
    {
        return DB::transaction(function () use ($order, $amount, $receivedOn, $reference, $note, $userId): ManualCodReceipt {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->payment_method !== 'cod') {
                throw CodReceiptRefused::notCod();
            }

            $outstanding = $this->receivables->forOrder($locked->id);
            $recognised = DB::table('financial_transactions')->where('source_type', 'order')
                ->where('source_id', $locked->id)->where('type', LedgerEntryType::SaleRevenue->value)->exists();
            if (! $recognised) {
                throw CodReceiptRefused::notRecognised();
            }
            if ($amount->greaterThan($outstanding)) {
                throw CodReceiptRefused::exceedsOutstanding($outstanding->toDecimal());
            }

            $receipt = ManualCodReceipt::query()->create([
                'order_id' => $locked->id,
                'amount' => $amount,
                'received_on' => $receivedOn->toDateString(),
                'reference' => $reference,
                'note' => $note,
                'recorded_by' => $userId,
            ]);

            $this->ledger->record(new LedgerEntry(
                type: LedgerEntryType::CodReceived,
                direction: Direction::In,
                amount: $amount,
                occurredOn: $receivedOn,
                sourceType: 'manual_cod_receipt',
                sourceId: $receipt->id,
                isCashMovement: true,
                isRecognisedRevenue: false,
                description: "COD received for order {$locked->number}".($reference !== null ? " ({$reference})" : ''),
            ));

            return $receipt;
        });
    }
}
