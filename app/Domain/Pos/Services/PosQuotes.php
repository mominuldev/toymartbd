<?php

declare(strict_types=1);

namespace App\Domain\Pos\Services;

use App\Domain\Cart\DTOs\PricingInput;
use App\Domain\Cart\Services\PricingPipeline;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Pos\DTOs\PosQuote;
use App\Domain\Pos\DTOs\TenderInput;
use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * Prices a register cart through THE checkout pricing pipeline (rule 1): promotions and
 * coupons exactly as the storefront applies them, no delivery line (nothing is delivered),
 * no loyalty or points (no such system exists). Read-only; safe inside ChargeSale's
 * transaction.
 *
 * Blockers, field-level, in the shape `errors[]` uses:
 *  - `lines`            nothing in the cart;
 *  - `lines.{id}`       not sellable, not sold at the counter (`products.channel_pos`), or
 *                       more than the branch has available;
 *  - `coupon_code`      the coupon did not apply (its own reason);
 *  - `customer_phone`   a discount applies but no customer phone was given — promotion and
 *                       coupon limits are counted per phone, so an anonymous sale cannot
 *                       consume them.
 *
 * A due tender's guards (permission, known customer, limit — PosDues) are tender errors, in
 * `tenders.errors` on `tenders.{i}.method`. Here they are read without locks (a preview);
 * ChargeSale re-checks the limit under the customer's row lock.
 */
final readonly class PosQuotes
{
    public function __construct(
        private PricingPipeline $pricing,
        private TenderPlanner $planner,
        private PosCustomers $customers,
        private PosDues $dues,
    ) {}

    /**
     * @param  array<int, int>  $lines  variant id → quantity
     * @param  list<TenderInput>|null  $tenders
     * @param  bool  $canSellOnDue  the cashier holds `pos.sell_on_due`
     */
    public function quote(int $locationId, array $lines, ?string $couponCode, ?PhoneNumber $phone, ?array $tenders = null, ?string $customerName = null, bool $canSellOnDue = false): PosQuote
    {
        $customer = $phone === null ? null : $this->customers->find($phone);
        $lines = array_filter($lines, static fn (int $q): bool => $q > 0);
        ksort($lines);
        $coupon = $couponCode !== null && trim($couponCode) !== '' ? trim($couponCode) : null;

        $breakdown = $this->pricing->price(new PricingInput(
            lines: $lines,
            couponCode: $coupon,
            phone: $phone,
            customerId: $customer?->id,
        ));

        $available = [];
        foreach (StockLevel::query()->where('location_id', $locationId)->whereIn('variant_id', array_keys($lines))->get() as $level) {
            $available[$level->variant_id] = max(0, $level->available());
        }

        $blockers = [];
        if ($lines === []) {
            $blockers[] = ['field' => 'lines', 'message' => (string) __('pos.cart_empty')];
        }
        foreach ($breakdown->unavailableVariantIds as $variantId) {
            $blockers[] = ['field' => 'lines.'.$variantId, 'message' => (string) __('cart.variant.unavailable')];
        }
        $offCounter = ProductVariant::query()->whereKey(array_keys($lines))
            ->whereHas('product', static fn ($q) => $q->withTrashed()->where('channel_pos', false))
            ->pluck('id')->all();
        foreach ($offCounter as $variantId) {
            $blockers[] = ['field' => 'lines.'.$variantId, 'message' => (string) __('pos.not_at_counter')];
        }
        foreach ($lines as $variantId => $quantity) {
            $have = $available[$variantId] ?? 0;
            if ($quantity > $have && ! in_array($variantId, $breakdown->unavailableVariantIds, true)) {
                $blockers[] = ['field' => 'lines.'.$variantId, 'message' => $have > 0
                    ? (string) __('pos.stock.only_here', ['available' => $have])
                    : (string) __('pos.stock.none_here')];
            }
        }
        if ($breakdown->coupon !== null && ! $breakdown->coupon->applied()) {
            $blockers[] = ['field' => 'coupon_code', 'message' => (string) $breakdown->coupon->message()];
        }
        if ($phone === null && ($coupon !== null || ! $breakdown->discountTotal()->isZero())) {
            $blockers[] = ['field' => 'customer_phone', 'message' => (string) __('pos.phone_for_discount')];
        }

        $plan = $tenders === null ? null : $this->planner->plan($breakdown->grandTotal, $tenders);
        if ($plan !== null && $plan->hasDue()) {
            $openDue = $customer === null ? Money::zero() : $this->dues->openDue($customer->id);
            $plan = $plan->withErrors($this->dues->guard($plan, $canSellOnDue, $phone !== null, $customer, $customerName, $openDue));
        }

        return new PosQuote(
            breakdown: $breakdown,
            available: $available,
            blockers: $blockers,
            tenders: $plan,
        );
    }
}
