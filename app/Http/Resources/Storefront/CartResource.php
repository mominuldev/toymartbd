<?php

declare(strict_types=1);

namespace App\Http\Resources\Storefront;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\Models\CartItem;
use App\Http\Resources\ApiResource;
use App\Support\Money;
use DateTimeImmutable;
use DateTimeZone;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;

/**
 * The cart, priced by the pricing pipeline (P4-CRT-01/02, docs/api/cart.md). Every
 * money figure is a decimal string computed server-side; the storefront renders them
 * and never adds anything up itself (rule 1). `adjustments` are signed rows that sum
 * exactly to `totals.grand_total`.
 *
 * @property PricedCart $resource
 */
#[SchemaName('Cart')]
final class CartResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $cart = $this->resource->cart;
        $breakdown = $this->resource->breakdown;
        $available = $this->resource->available;

        /** @var array<int, LineBreakdown> $lines */
        $lines = [];
        foreach ($breakdown->lines as $line) {
            $lines[$line->variant->id] = $line;
        }

        $coupon = $breakdown->coupon;

        return [
            /**
             * Opaque cart token: store it in an httpOnly cookie and send it back as `X-Cart-Token`.
             *
             * @var string
             */
            'token' => $cart->token,
            /**
             * ISO 8601, UTC. Extended by every change.
             *
             * @var string
             *
             * @format date-time
             */
            'expires_at' => DateTimeImmutable::createFromInterface($cart->expires_at)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            /** @var int */
            'item_count' => $breakdown->itemCount(),
            /**
             * One entry per line. `sellable` false: the item can no longer be bought (it is
             * excluded from every total; remove it). `in_stock` false: `quantity` exceeds
             * `available_quantity` (checkout will refuse until it is lowered).
             *
             * @var list<array{id: int, variant_id: int, product: array{id: int, name: string, name_bn: string|null, slug: string}, variant: array{sku: string, name: string|null, name_bn: string|null}, quantity: int, available_quantity: int, in_stock: bool, sellable: bool, unit_price: string, added_unit_price: string, price_changed: bool, subtotal: string, discount: string, total: string}>
             */
            'items' => $cart->items->map(fn (CartItem $item): array => self::item($item, $lines[$item->variant_id] ?? null, $available[$item->variant_id] ?? 0))->values()->all(),
            /**
             * The coupon on the cart, if any. `applied` false: `reason` (stable code) and
             * `message` (localised) say why; the cart keeps it so the customer sees why.
             *
             * @var array{code: string, applied: bool, reason: string|null, message: string|null, discount: string, free_shipping: bool}|null
             */
            'coupon' => $coupon === null ? null : [
                'code' => $coupon->code,
                'applied' => $coupon->applied(),
                'reason' => $coupon->rejection?->value,
                'message' => $coupon->message(),
                'discount' => ($coupon->discount !== null ? $coupon->discount->amount : Money::zero())->toDecimal(),
                'free_shipping' => $coupon->discount !== null && $coupon->discount->freeShipping,
            ],
            /**
             * Signed rows in pipeline order (subtotal, each promotion and the coupon as
             * negatives, then delivery) that sum exactly to `totals.grand_total`.
             *
             * @var list<array{type: 'subtotal'|'promotion'|'coupon'|'shipping', id: int|null, label: string|null, label_bn: string|null, amount: string}>
             */
            'adjustments' => array_map(static fn (array $row): array => [...$row, 'amount' => $row['amount']->toDecimal()], $breakdown->itemised()),
            /**
             * `shipping_charge` is null without a destination (`?district_id=`) or when it is not
             * serviced; `grand_total` then excludes delivery.
             *
             * @var array{subtotal: string, promotion_discount: string, coupon_discount: string, discount_total: string, shipping_charge: string|null, grand_total: string}
             */
            'totals' => [
                'subtotal' => $breakdown->subtotal->toDecimal(),
                'promotion_discount' => $breakdown->promotionDiscount->toDecimal(),
                'coupon_discount' => $breakdown->couponDiscount->toDecimal(),
                'discount_total' => $breakdown->discountTotal()->toDecimal(),
                'shipping_charge' => $breakdown->shippingCharge?->toDecimal(),
                'grand_total' => $breakdown->grandTotal->toDecimal(),
            ],
            /** The delivery quote for the requested destination — the same object `GET /delivery-quote` returns, for this cart. */
            'delivery' => $breakdown->delivery === null ? null : new DeliveryQuoteResource($breakdown->delivery),
            /**
             * Every line sellable and in stock, the coupon (if any) applied and a serviced destination given.
             *
             * @var bool
             */
            'checkout_ready' => $breakdown->isCheckoutReady() && $cart->items->every(fn (CartItem $i): bool => $i->quantity <= ($available[$i->variant_id] ?? 0)),
        ];
    }

    /** @return array<string, mixed> */
    private static function item(CartItem $item, ?LineBreakdown $line, int $available): array
    {
        $variant = $item->variant;
        $product = $variant->product;
        $zero = '0.00';

        return [
            'id' => $item->id,
            'variant_id' => $item->variant_id,
            'product' => ['id' => $product->id, 'name' => $product->name, 'name_bn' => $product->name_bn, 'slug' => $product->slug],
            'variant' => ['sku' => $variant->sku, 'name' => $variant->name, 'name_bn' => $variant->name_bn],
            'quantity' => $item->quantity,
            'available_quantity' => $available,
            'in_stock' => $item->quantity <= $available,
            'sellable' => $line !== null,
            'unit_price' => ($line !== null ? $line->unitPrice : $variant->price)->toDecimal(),
            'added_unit_price' => $item->unit_price->toDecimal(),
            'price_changed' => ! ($line !== null ? $line->unitPrice : $variant->price)->equals($item->unit_price),
            'subtotal' => $line?->subtotal->toDecimal() ?? $zero,
            'discount' => $line?->discount()->toDecimal() ?? $zero,
            'total' => $line?->total->toDecimal() ?? $zero,
        ];
    }
}
