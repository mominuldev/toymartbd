<?php

declare(strict_types=1);

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\DTOs\PricedCart;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\CartPricing;
use App\Domain\Cart\Services\CartResolver;
use App\Domain\Cart\Services\PricingPipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put a coupon on the cart. It is validated against the cart as it stands (window,
 * limits, scope, minimum) through the pricing pipeline; a rejected code is a 422 on
 * `code` with the localised reason and leaves the cart unchanged. One coupon per cart:
 * a new code replaces the old one.
 *
 * Per-customer limits need the phone, which the cart does not have: they are checked
 * again at checkout, and enforced under a lock when the usage is recorded.
 */
final readonly class ApplyCoupon
{
    public function __construct(
        private CartResolver $carts,
        private PricingPipeline $pipeline,
        private CartPricing $pricing,
    ) {}

    /** @throws ValidationException */
    public function handle(string $token, string $code): PricedCart
    {
        return DB::transaction(function () use ($token, $code): PricedCart {
            $cart = $this->carts->lock($token);
            $cart->load('items');

            $outcome = $this->pipeline->price(new PricingInput($cart->lines(), $code, customerId: $cart->customer_id))->coupon;
            if ($outcome === null || ! $outcome->applied() || $outcome->coupon === null) {
                throw ValidationException::withMessages(['code' => $outcome?->message() ?? __('cart.coupon.not_found')]);
            }

            $cart->coupon_id = $outcome->coupon->id;
            $cart->save();

            return $this->pricing->priceAndStore($cart);
        });
    }
}
