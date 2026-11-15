<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Fulfilment\DTOs\DeliveryQuote;
use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\DTOs\CouponOutcome;
use App\Domain\Marketing\Services\Allocator;
use App\Support\Money;
use LogicException;

/**
 * The pricing pipeline's answer (P4-CRT-02): base → promotions → coupon → delivery →
 * total, itemised. Invariants, asserted on construction:
 *
 *   subtotal - promotionDiscount - couponDiscount - manualDiscount + (shippingCharge ?? 0) = grandTotal
 *   Σ lines.total = subtotal - promotionDiscount - couponDiscount - manualDiscount
 *
 * `manualDiscount` is a cashier's discount at the POS, applied last by
 * {@see self::withManualDiscount()}; the pricing pipeline itself never sets it, so it is
 * zero for the storefront, the cart and checkout.
 *
 * `unavailableVariantIds` lists requested variants that cannot be sold (archived,
 * unpublished, deleted); they are excluded from every figure. Checkout must refuse
 * while it is non-empty, as it must while `coupon` is set but not applied.
 * `delivery` is null without a destination; `shippingCharge` is null then and when
 * the destination is not serviced.
 */
final readonly class PriceBreakdown
{
    public Money $manualDiscount;

    /**
     * @param  list<LineBreakdown>  $lines
     * @param  list<int>  $unavailableVariantIds
     * @param  list<AppliedDiscount>  $promotions
     */
    public function __construct(
        public array $lines,
        public array $unavailableVariantIds,
        public array $promotions,
        public ?CouponOutcome $coupon,
        public ?DeliveryQuote $delivery,
        public Money $subtotal,
        public Money $promotionDiscount,
        public Money $couponDiscount,
        public ?Money $shippingCharge,
        public Money $grandTotal,
        ?Money $manualDiscount = null,
        public ?string $manualDiscountReason = null,
    ) {
        $this->manualDiscount = $manualDiscount ?? Money::zero();
        $sum = array_reduce($this->itemised(), static fn (Money $c, array $row): Money => $c->plus($row['amount']), Money::zero());
        $lineSum = array_reduce($lines, static fn (Money $c, LineBreakdown $l): Money => $c->plus($l->total), Money::zero());
        if (! $sum->equals($grandTotal) || ! $lineSum->equals($subtotal->minus($promotionDiscount)->minus($couponDiscount)->minus($this->manualDiscount))) {
            throw new LogicException('Price breakdown does not sum to its total.');
        }
    }

    public function discountTotal(): Money
    {
        return $this->promotionDiscount->plus($this->couponDiscount)->plus($this->manualDiscount);
    }

    /**
     * The same breakdown with a cashier's discount taken off the goods, after promotions and the
     * coupon. Spread over the lines in proportion to what each still costs (never below zero),
     * so every order item carries its share. `$amount` beyond the goods total is clamped.
     */
    public function withManualDiscount(Money $amount, string $reason): self
    {
        $bases = [];
        foreach ($this->lines as $i => $line) {
            $bases[$i] = $line->total;
        }
        $shares = Allocator::spread($amount, $bases);
        $applied = Allocator::sum($shares);

        $lines = [];
        foreach ($this->lines as $i => $line) {
            $share = $shares[$i] ?? Money::zero();
            $lines[] = new LineBreakdown(
                $line->variant, $line->quantity, $line->unitPrice, $line->subtotal, $line->promotionDiscount, $line->couponDiscount,
                $line->total->minus($share), $line->manualDiscount->plus($share),
            );
        }

        return new self(
            lines: $lines,
            unavailableVariantIds: $this->unavailableVariantIds,
            promotions: $this->promotions,
            coupon: $this->coupon,
            delivery: $this->delivery,
            subtotal: $this->subtotal,
            promotionDiscount: $this->promotionDiscount,
            couponDiscount: $this->couponDiscount,
            shippingCharge: $this->shippingCharge,
            grandTotal: $this->grandTotal->minus($applied),
            manualDiscount: $this->manualDiscount->plus($applied),
            manualDiscountReason: $reason,
        );
    }

    /** Goods after discounts — what the free-delivery threshold is measured against. */
    public function merchandiseTotal(): Money
    {
        return $this->subtotal->minus($this->discountTotal());
    }

    public function itemCount(): int
    {
        return array_sum(array_map(static fn (LineBreakdown $l): int => $l->quantity, $this->lines));
    }

    /** True when checkout may proceed on these figures (every line sellable, coupon — if any — applied, destination serviced). */
    public function isCheckoutReady(): bool
    {
        return $this->lines !== []
            && $this->unavailableVariantIds === []
            && ($this->coupon === null || $this->coupon->applied())
            && $this->shippingCharge !== null;
    }

    /**
     * Signed rows that sum exactly to `grandTotal`: the subtotal, each promotion and the
     * coupon as negatives, then delivery.
     *
     * @return list<array{type: 'subtotal'|'promotion'|'coupon'|'manual'|'shipping', id: int|null, label: string|null, label_bn: string|null, amount: Money}>
     */
    public function itemised(): array
    {
        $rows = [['type' => 'subtotal', 'id' => null, 'label' => null, 'label_bn' => null, 'amount' => $this->subtotal]];

        foreach ($this->promotions as $promotion) {
            $rows[] = ['type' => 'promotion', 'id' => $promotion->id, 'label' => $promotion->label, 'label_bn' => $promotion->labelBn, 'amount' => $promotion->amount->negate()];
        }

        $discount = $this->coupon?->discount;
        if ($discount !== null) {
            $rows[] = ['type' => 'coupon', 'id' => $discount->id, 'label' => $discount->label, 'label_bn' => null, 'amount' => $discount->amount->negate()];
        }

        if (! $this->manualDiscount->isZero()) {
            $rows[] = ['type' => 'manual', 'id' => null, 'label' => $this->manualDiscountReason, 'label_bn' => null, 'amount' => $this->manualDiscount->negate()];
        }
        if ($this->shippingCharge !== null) {
            $rows[] = ['type' => 'shipping', 'id' => null, 'label' => null, 'label_bn' => null, 'amount' => $this->shippingCharge];
        }

        return $rows;
    }
}
