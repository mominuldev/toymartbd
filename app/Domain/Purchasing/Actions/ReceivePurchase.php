<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\Actions;

use App\Domain\Inventory\Actions\ReceivePurchasedStock;
use App\Domain\Inventory\Models\StockLocation;
use App\Domain\Purchasing\DTOs\ReceiptData;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Events\LandedCostsPaidToThirdParty;
use App\Domain\Purchasing\Events\PurchaseReceived;
use App\Domain\Purchasing\Events\PurchaseStatusChanged;
use App\Domain\Purchasing\Exceptions\PurchaseConflict;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReceipt;
use App\Domain\Purchasing\Models\PurchaseReceiptItem;
use App\Domain\Purchasing\Services\Decimals;
use App\Domain\Purchasing\Services\PurchaseTotals;
use App\Domain\Purchasing\Services\ReceiptCalculator;
use App\Support\DhakaDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receives a delivery against a PO — DATABASE-DESIGN §4.6, in one transaction:
 *
 *   1. lock the PO row (two staff receiving the same PO queue; neither can over-receive)
 *   2. compute the receipt (ReceiptCalculator — the same path as the dialog's quote)
 *   3. write the receipt document (GRN-YYYY-NNNN)
 *   4. ReceivePurchasedStock: lock stock levels in ascending variant id (rule 9), step
 *      the weighted-average cost, append `purchase_in` movements at the landed unit cost,
 *      and set each variant's cost_price to its new average
 *   5. receipt lines, PO lines (quantity_received, landed_unit_cost), PO totals and status
 *
 * The PO lock is always taken before any stock-level lock, and no stock writer takes a
 * PO lock, so the two orders cannot deadlock.
 *
 * Landed costs a third party charged (`costs_paid_to_supplier = false`) are announced with
 * LandedCostsPaidToThirdParty; Finance books them as a capitalised expense — cash out only,
 * never a P&L cost, because they are already inside the landed unit cost.
 *
 * Not written here: any ledger entry. Receiving stock is not an expense — its cost
 * reaches the P&L as `cogs` when the goods sell (rule 7 snapshot), and cash leaves when
 * the supplier is paid (RecordSupplierPayment → `supplier_payment`). This departs from
 * the "inventory purchase" line sketched in §4.6; see the as-built note there.
 */
final readonly class ReceivePurchase
{
    public function __construct(
        private ReceiptCalculator $calculator,
        private ReceivePurchasedStock $receiveStock,
    ) {}

    /**
     * @throws PurchaseConflict when the PO is not ordered / partially received (409)
     * @throws ValidationException on an over-receipt, an unknown line or an inactive location
     */
    public function handle(Purchase $purchase, ReceiptData $data, ?int $userId = null): PurchaseReceipt
    {
        return DB::transaction(function () use ($purchase, $data, $userId): PurchaseReceipt {
            $locked = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            if (! $locked->status->canReceive()) {
                throw PurchaseConflict::notReceivable((string) $locked->number, $locked->status->value);
            }
            $locationId = $data->locationId ?? $locked->location_id;
            if (! StockLocation::query()->whereKey($locationId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['location_id' => __('purchasing.receive.location_inactive')]);
            }

            $locked->load('items');
            $quote = $this->calculator->quote($locked, $data);

            $receipt = PurchaseReceipt::query()->create([
                'purchase_id' => $locked->id,
                'location_id' => $quote->locationId,
                'received_at' => now(),
                'freight' => $quote->freight,
                'customs_duty' => $quote->customsDuty,
                'other_cost' => $quote->otherCost,
                'apportion_method' => $quote->method,
                'costs_paid_to_supplier' => $quote->costsPaidToSupplier,
                'costs_vendor' => $quote->costsPaidToSupplier ? null : $data->costsVendor,
                'costs_reference' => $quote->costsPaidToSupplier ? null : $data->costsReference,
                'costs_payment_method' => $quote->costsPaidToSupplier ? null : $data->costsPaymentMethod,
                'goods_value' => $quote->goodsValue(),
                'payable_amount' => $quote->payableAmount(),
                'note' => $data->note,
                'created_by' => $userId,
            ]);
            $receipt->number = PurchaseReceipt::numberFor($receipt->id, $receipt->created_at ?? now());
            $receipt->save();

            $steps = $this->receiveStock->handle(
                $quote->locationId,
                array_map(static fn ($line): array => [
                    'variant_id' => $line->variantId,
                    'quantity' => $line->quantity,
                    'landed_unit_cost' => $line->landedUnitCost,
                ], $quote->lines),
                PurchaseReceipt::REFERENCE_TYPE,
                $receipt->id,
                $userId,
                $locked->number,
            );

            /** @var array<int, PurchaseItem> $items */
            $items = $locked->items->keyBy('id')->all();
            foreach ($quote->lines as $line) {
                $step = $steps[$line->variantId];
                PurchaseReceiptItem::query()->create([
                    'receipt_id' => $receipt->id,
                    'purchase_item_id' => $line->purchaseItemId,
                    'variant_id' => $line->variantId,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unitCost,
                    'line_value' => $line->lineValue,
                    'allocated_cost' => $line->allocatedCost,
                    'landed_unit_cost' => $line->landedUnitCost,
                    'avg_cost_before' => $step['avg_cost_before'],
                    'avg_cost_after' => $step['avg_cost_after'],
                ]);

                $item = $items[$line->purchaseItemId];
                $received = $item->quantity_received + $line->quantity;
                $landedSoFar = bcmul((string) $item->quantity_received, $item->landed_unit_cost ?? '0', 8);
                $item->landed_unit_cost = Decimals::round(bcdiv(bcadd($landedSoFar, bcmul((string) $line->quantity, $line->landedUnitCost, 8), 8), (string) $received, 8), 4);
                $item->quantity_received = $received;
                $item->save();
            }

            $outstanding = array_sum(array_map(static fn (PurchaseItem $i): int => $i->quantityOutstanding(), $items));
            $from = $locked->status;
            $to = $outstanding === 0 ? PurchaseStatus::Received : PurchaseStatus::PartiallyReceived;

            $locked->shipping_cost = $locked->shipping_cost->plus($quote->freight);
            $locked->customs_duty = $locked->customs_duty->plus($quote->customsDuty);
            $locked->other_cost = $locked->other_cost->plus($quote->otherCost);
            $locked->received_value = $locked->received_value->plus($quote->payableAmount());
            $locked->status = $to;
            if ($to === PurchaseStatus::Received) {
                $locked->received_at = now();
            }
            PurchaseTotals::refresh($locked);
            $locked->save();

            PurchaseReceived::dispatch($locked->id, $receipt->id, $locked->supplier_id, $quote->payableAmount()->toDecimal(), $to === PurchaseStatus::Received);
            if ($from !== $to) {
                PurchaseStatusChanged::dispatch($locked->id, $from->value, $to->value);
            }
            if (! $quote->costsPaidToSupplier && ! $quote->landedTotal()->isZero()) {
                LandedCostsPaidToThirdParty::dispatch(
                    $receipt->id,
                    (string) $receipt->number,
                    (string) $locked->number,
                    $quote->freight->toDecimal(),
                    $quote->customsDuty->toDecimal(),
                    $quote->otherCost->toDecimal(),
                    $quote->landedTotal()->toDecimal(),
                    DhakaDate::dayOf($receipt->received_at),
                    $data->costsVendor,
                    $data->costsReference,
                    $data->costsPaymentMethod,
                    $userId,
                );
            }

            return $receipt->load('items');
        });
    }
}
