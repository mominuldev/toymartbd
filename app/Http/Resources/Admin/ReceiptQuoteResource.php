<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\DTOs\ReceiptQuote;
use App\Domain\Purchasing\DTOs\ReceiptQuoteLine;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The receive dialog's preview: every figure the receipt would write, computed by the
 * same ReceiptCalculator (rule 1). Needs purchasing.receive; the receiver enters landed
 * cost, so cost is shown (the inventory_staff role holds catalog.view_cost for this).
 *
 * @property ReceiptQuote $resource
 */
#[SchemaName('PurchaseReceiptQuote')]
final class ReceiptQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            'location_id' => $quote->locationId,
            /** @var 'value'|'quantity'|'weight' */
            'apportion_method' => $quote->method->value,
            'units' => $quote->units(),
            'goods_value' => $quote->goodsValue()->toDecimal(),
            'freight' => $quote->freight->toDecimal(),
            'customs_duty' => $quote->customsDuty->toDecimal(),
            'other_cost' => $quote->otherCost->toDecimal(),
            'landed_total' => $quote->landedTotal()->toDecimal(),
            /** What the supplier is owed for this delivery. */
            'payable_amount' => $quote->payableAmount()->toDecimal(),
            /** @var list<array{purchase_item_id: int, variant_id: int, quantity: int, quantity_outstanding_after: int, unit_cost: string, line_value: string, allocated_cost: string, landed_unit_cost: string, on_hand: int, avg_cost_before: string, avg_cost_after: string}> */
            'lines' => array_map(static fn (ReceiptQuoteLine $line): array => [
                'purchase_item_id' => $line->purchaseItemId,
                'variant_id' => $line->variantId,
                'quantity' => $line->quantity,
                'quantity_outstanding_after' => $line->quantityOutstandingAfter(),
                'unit_cost' => $line->unitCost,
                'line_value' => $line->lineValue->toDecimal(),
                'allocated_cost' => $line->allocatedCost->toDecimal(),
                'landed_unit_cost' => $line->landedUnitCost,
                'on_hand' => $line->onHand,
                'avg_cost_before' => $line->avgCostBefore,
                'avg_cost_after' => $line->avgCostAfter,
            ], $quote->lines),
        ];
    }
}
