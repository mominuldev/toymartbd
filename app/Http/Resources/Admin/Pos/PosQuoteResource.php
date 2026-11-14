<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\Pos;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Pos\DTOs\PosQuote;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The register's figures — every number the cart panel shows (rule 1): lines with their own
 * totals, the itemised rows (subtotal, each promotion, the coupon) that sum to `grand_total`,
 * `blockers` (what stops a charge, field-level) and, when tenders were sent, how they settle:
 * `remaining` while short, `change` to give back from cash.
 *
 * @property PosQuote $resource
 */
#[SchemaName('PosQuote')]
final class PosQuoteResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $quote = $this->resource;
        $breakdown = $quote->breakdown;
        $plan = $quote->tenders;

        return [
            /** @var list<array{variant_id: int, name: string, name_bn: string|null, variant_name: string|null, quantity: int, unit_price: string, subtotal: string, discount: string, total: string, available: int, image_url: string|null}> */
            'lines' => array_map(static function (LineBreakdown $line) use ($quote): array {
                $variant = $line->variant;
                $variant->loadMissing('product.images');
                $images = $variant->product->images->sortBy('position');
                $image = $images->firstWhere('variant_id', $variant->id) ?? $images->firstWhere('is_primary', true) ?? $images->first();

                return [
                    'variant_id' => $variant->id,
                    'name' => $variant->product->name,
                    'name_bn' => $variant->product->name_bn,
                    'variant_name' => $variant->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice->toDecimal(),
                    'subtotal' => $line->subtotal->toDecimal(),
                    'discount' => $line->discount()->toDecimal(),
                    'total' => $line->total->toDecimal(),
                    'available' => $quote->available[$variant->id] ?? 0,
                    'image_url' => $image instanceof ProductImage ? $image->url(160) : null,
                ];
            }, $breakdown->lines),
            /**
             * Signed rows that sum to `grand_total` (no delivery at the counter, so free-delivery promotions are left out).
             *
             * @var list<array{type: 'subtotal'|'promotion'|'coupon'|'shipping', label: string|null, label_bn: string|null, amount: string}>
             */
            'rows' => self::rows($quote),
            'subtotal' => $breakdown->subtotal->toDecimal(),
            'discount_total' => $breakdown->discountTotal()->toDecimal(),
            'grand_total' => $breakdown->grandTotal->toDecimal(),
            /** @var int */
            'item_count' => $breakdown->itemCount(),
            /** @var list<array{field: string, message: string}> */
            'blockers' => $quote->blockers,
            'chargeable' => $quote->chargeable(),
            /** @var array{tendered: string, cash_tendered: string, remaining: string, change: string, errors: list<array{field: string, message: string}>}|null */
            'tenders' => $plan === null ? null : [
                'tendered' => $plan->tendered->toDecimal(),
                'cash_tendered' => $plan->cashTendered->toDecimal(),
                'remaining' => $plan->remaining->toDecimal(),
                'change' => $plan->change->toDecimal(),
                'errors' => $plan->errors,
            ],
        ];
    }

    /**
     * The breakdown's itemised rows without delivery: nothing is delivered at the counter, so a
     * free-delivery promotion (a 0.00 row in the storefront breakdown) is not shown. The rest
     * still sums to `grand_total`.
     *
     * @return list<array{type: 'subtotal'|'promotion'|'coupon'|'shipping', label: string|null, label_bn: string|null, amount: string}>
     */
    private static function rows(PosQuote $quote): array
    {
        $rows = [];
        foreach ($quote->breakdown->itemised() as $row) {
            if ($row['type'] === 'shipping' || ($row['type'] === 'promotion' && $row['amount']->isZero())) {
                continue;
            }
            $rows[] = ['type' => $row['type'], 'label' => $row['label'], 'label_bn' => $row['label_bn'], 'amount' => $row['amount']->toDecimal()];
        }

        return $rows;
    }
}
