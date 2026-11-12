<?php

declare(strict_types=1);

namespace App\Domain\Cart\Services;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Models\Cart;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Inventory\Services\StockAvailability;
use App\Support\Money;

/**
 * Prices a cart through the one PricingPipeline, and (inside a cart Action's
 * transaction) caches the result on the cart row and its lines.
 */
final readonly class CartPricing
{
    public function __construct(
        private PricingPipeline $pipeline,
        private StockAvailability $availability,
    ) {}

    public function price(Cart $cart, ?District $district = null, ?Upazila $upazila = null): PricedCart
    {
        // Archived/soft-deleted products still render on the line (as not sellable).
        $cart->load(['items.variant.product' => fn ($q) => $q->withTrashed(), 'coupon']);

        $breakdown = $this->pipeline->price(new PricingInput(
            lines: $cart->lines(),
            couponCode: $cart->coupon?->code,
            district: $district,
            upazila: $upazila,
            customerId: $cart->customer_id,
        ));

        return new PricedCart($cart, $breakdown, $this->availability->forVariants(array_keys($cart->lines())));
    }

    /** Re-price and cache the totals on the cart and its lines. Call from a cart Action, inside its transaction. */
    public function priceAndStore(Cart $cart): PricedCart
    {
        $priced = $this->price($cart);
        $breakdown = $priced->breakdown;

        $totals = [];
        foreach ($breakdown->lines as $line) {
            $totals[$line->variant->id] = $line->total;
        }
        foreach ($cart->items as $item) {
            $item->line_total = $totals[$item->variant_id] ?? Money::zero();
            $item->save();
        }

        $cart->subtotal = $breakdown->subtotal;
        $cart->discount_total = $breakdown->discountTotal();
        $cart->shipping_total = $breakdown->shippingCharge ?? Money::zero();
        $cart->grand_total = $breakdown->grandTotal;
        $cart->item_count = $breakdown->itemCount();
        $cart->extendLifetime();
        $cart->save();

        return $priced;
    }
}
