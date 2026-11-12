<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Services;

use App\Domain\Cart\DTOs\PriceBreakdown;
use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\District;
use App\Domain\Customer\Models\Upazila;
use App\Domain\Ordering\Models\Order;
use App\Support\PhoneNumber;

/**
 * Staff quotes (P4-ORD-06/07) — thin wrappers that build a {@see PricingInput} and call THE
 * pricing pipeline, so a phone order and an edit are priced by exactly the code path web
 * checkout uses (rule 1). Read-only; safe inside an Action's transaction.
 */
final readonly class OrderQuotes
{
    public function __construct(private PricingPipeline $pricing) {}

    /**
     * A new staff-entered order — the same input PlaceOrder builds from a cart.
     *
     * @param  array<int, int>  $lines  variant id → quantity
     */
    public function forNewOrder(array $lines, ?string $couponCode, ?District $district, ?Upazila $upazila, ?PhoneNumber $phone): PriceBreakdown
    {
        $customerId = $phone === null ? null : Customer::query()->withTrashed()->where('phone', $phone->e164())->value('id');

        return $this->pricing->price(new PricingInput(
            lines: $lines,
            couponCode: $couponCode,
            district: $district,
            upazila: $upazila,
            phone: $phone,
            customerId: is_int($customerId) ? $customerId : null,
        ));
    }

    /**
     * An existing order with new lines and/or a new destination. Priced as at the order's
     * placement time, so the promotions and coupon window the customer was promised still
     * hold; the variant base price is the catalogue price now (there is no price history).
     * The order's own coupon is re-evaluated without the phone: its per-customer and
     * first-order checks were made at placement, and this order's own usage would
     * otherwise count against it — CouponUsages::record() re-checks the limits on save.
     *
     * @param  array<int, int>  $lines  variant id → quantity
     */
    public function forEdit(Order $order, array $lines, District $district, ?Upazila $upazila): PriceBreakdown
    {
        return $this->pricing->price(new PricingInput(
            lines: $lines,
            couponCode: $order->coupon_code,
            district: $district,
            upazila: $upazila,
            now: $order->placed_at ?? $order->created_at,
        ));
    }

    /**
     * Why these figures cannot become an order, as `{field, message}` rows; empty when
     * they can. `$requireCoupon`: an edited order must keep its coupon applied.
     *
     * @return list<array{field: string, message: string}>
     */
    public static function blockers(PriceBreakdown $breakdown, ?Upazila $upazila, bool $requireCoupon = false): array
    {
        $errors = [];
        if ($breakdown->lines === [] && $breakdown->unavailableVariantIds === []) {
            $errors[] = ['field' => 'lines', 'message' => (string) __('orders.compose.no_lines')];
        }
        foreach ($breakdown->unavailableVariantIds as $variantId) {
            $errors[] = ['field' => 'lines.'.$variantId, 'message' => (string) __('cart.variant.unavailable')];
        }
        if ($breakdown->coupon !== null && ! $breakdown->coupon->applied()) {
            $errors[] = ['field' => 'coupon_code', 'message' => (string) $breakdown->coupon->message()];
        } elseif ($requireCoupon && $breakdown->coupon === null) {
            $errors[] = ['field' => 'coupon_code', 'message' => (string) __('orders.compose.coupon_lost')];
        }
        if ($breakdown->delivery !== null && $breakdown->shippingCharge === null) {
            $errors[] = ['field' => $upazila !== null ? 'upazila_id' : 'district_id', 'message' => (string) __('checkout.not_serviced')];
        }

        return $errors;
    }
}
