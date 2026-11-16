<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Purchasing\DTOs\PurchaseQuote;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The New purchase order form's preview: each line total and the order total, computed by
 * PurchaseTotals exactly as the saved PO will be (rule 1). Needs purchasing.create, which
 * enters unit costs, so cost is shown.
 *
 * @property PurchaseQuote $resource
 */
#[SchemaName('PurchaseQuote')]
final class PurchaseQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;

        return [
            /** @var int */
            'products' => count($quote->lines),
            /** @var int */
            'units' => $quote->units(),
            /** @var string */
            'subtotal' => $quote->subtotal->toDecimal(),
            /** @var string */
            'discount' => $quote->discount->toDecimal(),
            /** @var string */
            'total' => $quote->total->toDecimal(),
            /** True when a supplier was named, so a null `last_unit_cost` means "new to this supplier". */
            'supplier_known' => $quote->supplierKnown,
            /** @var list<array{variant_id: int, quantity: int, line_total: string, last_unit_cost: string|null}> */
            'lines' => array_map(static fn (array $line): array => [
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total']->toDecimal(),
                'last_unit_cost' => $line['last_unit_cost'],
            ], $quote->lines),
        ];
    }
}
