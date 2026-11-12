<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockLocation;
use App\Http\Resources\ApiResource;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * A staff order quote (P4-ORD-06/07) — the pricing pipeline's answer, itemised, so the
 * admin shows figures and never computes one (rule 1). `blockers` lists why it cannot
 * become an order yet (`{field, message}`); `ready` is `blockers === []`. Money is a
 * decimal string. `available` per line is sellable stock now (on hand − reserved).
 *
 * @property PriceBreakdown $resource
 */
#[SchemaName('OrderQuote')]
final class OrderQuoteResource extends ApiResource
{
    /** @param  list<array{field: string, message: string}>  $blockers */
    public function __construct(PriceBreakdown $resource, private readonly array $blockers = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $b = $this->resource;
        $ids = array_map(static fn (LineBreakdown $l): int => $l->variant->id, $b->lines);
        $levels = StockLevel::query()->where('location_id', StockLocation::defaultId())->whereIn('variant_id', $ids)->get()->keyBy('variant_id');
        $delivery = $b->delivery;

        return [
            /** @var list<array{variant_id: int, product_name: string, product_name_bn: string|null, variant_name: string|null, sku: string, quantity: int, unit_price: string, subtotal: string, discount: string, total: string, available: int}> */
            'lines' => array_map(static function (LineBreakdown $l) use ($levels): array {
                $level = $levels[$l->variant->id] ?? null;

                return [
                    'variant_id' => $l->variant->id,
                    'product_name' => $l->variant->product->name,
                    /** @var string|null */
                    'product_name_bn' => $l->variant->product->name_bn,
                    /** @var string|null */
                    'variant_name' => $l->variant->name,
                    'sku' => $l->variant->sku,
                    'quantity' => $l->quantity,
                    'unit_price' => $l->unitPrice->toDecimal(),
                    'subtotal' => $l->subtotal->toDecimal(),
                    'discount' => $l->discount()->toDecimal(),
                    'total' => $l->total->toDecimal(),
                    'available' => $level instanceof StockLevel ? max(0, $level->available()) : 0,
                ];
            }, $b->lines),
            /** @var list<int> */
            'unavailable_variant_ids' => $b->unavailableVariantIds,
            'subtotal' => $b->subtotal->toDecimal(),
            'promotion_discount' => $b->promotionDiscount->toDecimal(),
            'coupon' => $b->coupon === null ? null : [
                'code' => $b->coupon->code,
                'applied' => $b->coupon->applied(),
                'discount' => $b->couponDiscount->toDecimal(),
                /** @var string|null */
                'message' => $b->coupon->message(),
            ],
            'delivery' => $delivery === null ? null : [
                'serviceable' => $delivery->serviceable,
                /** @var 'inside_dhaka_metro'|'dhaka_suburb'|'outside_dhaka'|null */
                'zone' => $delivery->zone?->value,
                'weight_grams' => $delivery->weightGrams,
                'free_shipping' => $delivery->freeShipping,
                /** @var string|null */
                'cod_advance' => $delivery->codAdvance?->toDecimal(),
            ],
            /** @var string|null null without a destination, or when it is not serviced */
            'shipping_charge' => $b->shippingCharge?->toDecimal(),
            'discount_total' => $b->discountTotal()->toDecimal(),
            'grand_total' => $b->grandTotal->toDecimal(),
            'item_count' => $b->itemCount(),
            'ready' => $this->blockers === [],
            /** @var list<array{field: string, message: string}> */
            'blockers' => $this->blockers,
        ];
    }
}
