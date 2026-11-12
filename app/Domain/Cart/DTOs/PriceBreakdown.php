<?php

declare(strict_types=1);

namespace App\Domain\Cart\DTOs;

use App\Domain\Fulfilment\DTOs\DeliveryQuote;
use App\Domain\Marketing\DTOs\AppliedDiscount;
use App\Domain\Marketing\DTOs\CouponOutcome;
use App\Support\Money;
use LogicException;

/**
 * The pricing pipeline's answer (P4-CRT-02): base → promotions → coupon → delivery →
 * total, itemised. Invariants, asserted on construction:
 *
 *   subtotal - promotionDiscount - couponDiscount + (shippingCharge ?? 0) = grandTotal
 *   Σ lines.total = subtotal - promotionDiscount - couponDiscount
 *
 * `unavailableVariantIds` lists requested variants that cannot be sold (archived,
 * unpublished, deleted); they are excluded from every figure. Checkout must refuse
 * while it is non-empty, as it must while `coupon` is set but not applied.
 * `delivery` is null without a destination; `shippingCharge` is null then and when
 * the destination is not serviced.
 */
final readonly class PriceBreakdown
{
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
    ) {
        $sum = array_reduce($this->itemised(), static fn (Money $c, array $row): Money => $c->plus($row['amount']), Money::zero());
        $lineSum = array_reduce($lines, static fn (Money $c, LineBreakdown $l): Money => $c->plus($l->total), Money::zero());
        if (! $sum->equals($grandTotal) || ! $lineSum->equals($subtotal->minus($promotionDiscount)->minus($couponDiscount))) {
            throw new LogicException('Price breakdown does not sum to its total.');
        }
    }

    public function discountTotal(): Money
    {
        return $this->promotionDiscount->plus($this->couponDiscount);
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
     * @return list<array{type: 'subtotal'|'promotion'|'coupon'|'shipping', id: int|null, label: string|null, label_bn: string|null, amount: Money}>
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

        if ($this->shippingCharge !== null) {
            $rows[] = ['type' => 'shipping', 'id' => null, 'label' => null, 'label_bn' => null, 'amount' => $this->shippingCharge];
        }

        return $rows;
    }
}
