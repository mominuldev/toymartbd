<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\DTOs\LineBreakdown;
use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Fulfilment\DTOs\DeliveryParcel;
use App\Domain\Fulfilment\Services\DeliveryChargeResolver;
use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\DTOs\PricingLine;
use App\Domain\Marketing\Services\Allocator;
use App\Domain\Marketing\Services\CouponEvaluator;
use App\Domain\Marketing\Services\FreeDeliveryPromotions;
use App\Domain\Marketing\Services\PromotionEngine;
use App\Support\Money;

/**
 * THE pricing pipeline (P4-CRT-02): base → promotions → coupon → delivery → total.
 * The cart API and checkout (PlaceOrder, DATABASE-DESIGN §4.1 "re-price the cart from
 * current data") both call {@see self::price()} with a {@see PricingInput}; a second
 * implementation anywhere — including the browser — is a bug (rule 1).
 *
 *  1. base: each sellable line at the variant's current price
 *  2. promotions (PromotionEngine): priority order, stacking; a non-stackable match ends the chain;
 *     limits/budget per promotion (per-phone limits once the phone is known)
 *  3. coupon (CouponEvaluator) on the post-promotion amounts, minus the lines a promotion
 *     with `allows_coupons = false` discounted
 *  4. delivery (DeliveryChargeResolver — the estimator's code path) on the post-discount
 *     goods value; a valid free-shipping coupon or a free-delivery promotion the chain
 *     reached (threshold on the post-discount eligible goods) makes it 0 through §5.1
 *     step 1 — the promotion then shows as a 0.00 "promotion" row in the breakdown
 *  5. total
 *
 * Read-only: no writes, no transaction, no external call — safe to call inside
 * PlaceOrder's transaction.
 */
final readonly class PricingPipeline
{
    public function __construct(
        private SellableVariants $variants,
        private PromotionEngine $promotions,
        private CouponEvaluator $coupons,
        private DeliveryChargeResolver $delivery,
        private FreeDeliveryPromotions $freeDelivery,
    ) {}

    public function price(PricingInput $input): PriceBreakdown
    {
        $now = $input->now ?? now();
        [$variants, $unavailable] = $this->variants->split($input->lines, $now);

        $lines = [];
        foreach ($variants as $variant) {
            $lines[] = new PricingLine(
                $variant->id,
                $variant->product_id,
                $variant->product->brand_id,
                array_values(array_map('intval', $variant->product->categories->pluck('id')->all())),
                $variant->price,
                $input->lines[$variant->id],
            );
        }

        // 1–2. base and promotions
        $promotion = $this->promotions->apply($lines, $now, phone: $input->phone, customerId: $input->customerId);

        // 3. coupon
        $coupon = $input->couponCode === null || trim($input->couponCode) === ''
            ? null
            : $this->coupons->evaluate($input->couponCode, $lines, $promotion->remaining, $now, $input->phone, $input->customerId, $promotion->couponBlockedVariantIds());
        $couponLines = $coupon?->discount !== null ? $coupon->discount->perLine : [];

        $breakdownLines = [];
        foreach ($variants as $i => $variant) {
            $line = $lines[$i];
            $promotionDiscount = Allocator::sum(array_map(
                static fn ($d): Money => $d->perLine[$variant->id] ?? Money::zero(),
                $promotion->discounts,
            ));
            $couponDiscount = $couponLines[$variant->id] ?? Money::zero();
            $breakdownLines[] = new LineBreakdown(
                $variant, $line->quantity, $line->unitPrice, $line->subtotal(), $promotionDiscount, $couponDiscount,
                $line->subtotal()->minus($promotionDiscount)->minus($couponDiscount),
            );
        }

        $subtotal = Allocator::sum(array_map(static fn (PricingLine $l): Money => $l->subtotal(), $lines));
        $promotionDiscount = Allocator::sum(array_map(static fn ($d): Money => $d->amount, $promotion->discounts));
        $couponDiscount = $coupon?->discount !== null ? $coupon->discount->amount : Money::zero();
        $merchandise = $subtotal->minus($promotionDiscount)->minus($couponDiscount);

        // 4. delivery — one implementation for the estimator, the cart and checkout.
        $afterCoupon = [];
        foreach ($breakdownLines as $row) {
            $afterCoupon[$row->variant->id] = $row->total;
        }
        $couponFreeShipping = $coupon?->discount?->freeShipping === true;
        $freeDelivery = $couponFreeShipping ? null : $this->freeDelivery->choose($promotion->freeDelivery, $lines, $afterCoupon);
        $promotions = $promotion->discounts;
        $quote = null;
        if ($input->district !== null) {
            $quote = $this->delivery->quote(
                $input->district,
                $input->upazila,
                new DeliveryParcel(
                    array_combine(
                        array_map(static fn (PricingLine $l): int => $l->variantId, $lines),
                        array_map(static fn (PricingLine $l): int => $l->quantity, $lines),
                    ),
                    $merchandise,
                    $couponFreeShipping ? $coupon->code : null,
                    $freeDelivery?->id,
                ),
                $now,
            );
        }
        $shipping = $quote?->charge;
        if ($freeDelivery !== null && ($quote === null || $quote->freeShipping)) {
            // Named in the breakdown ("Promotion: Free delivery over ৳2000"); its saving is the delivery line's 0.
            $promotions[] = new AppliedDiscount(
                AppliedDiscount::PROMOTION, $freeDelivery->id, $freeDelivery->name, $freeDelivery->name_bn, Money::zero(), [],
                freeShipping: true, allowsCoupons: $freeDelivery->allows_coupons, kind: $freeDelivery->type->value,
            );
        }

        // 5. total
        return new PriceBreakdown(
            lines: $breakdownLines,
            unavailableVariantIds: $unavailable,
            promotions: $promotions,
            coupon: $coupon,
            delivery: $quote,
            subtotal: $subtotal,
            promotionDiscount: $promotionDiscount,
            couponDiscount: $couponDiscount,
            shippingCharge: $shipping,
            grandTotal: $shipping === null ? $merchandise : $merchandise->plus($shipping),
        );
    }
}
