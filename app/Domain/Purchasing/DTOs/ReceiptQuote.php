<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Support\Money;

/** A receipt computed but not written: the receive dialog's preview, and ReceivePurchase's plan. */
final readonly class ReceiptQuote
{
    /** @param  list<ReceiptQuoteLine>  $lines  only lines receiving units, in PO line order */
    public function __construct(
        public int $locationId,
        public array $lines,
        public LandedCostMethod $method,
        public Money $freight,
        public Money $customsDuty,
        public Money $otherCost,
        public bool $costsPaidToSupplier,
    ) {}

    public function units(): int
    {
        return array_sum(array_map(static fn (ReceiptQuoteLine $l): int => $l->quantity, $this->lines));
    }

    public function goodsValue(): Money
    {
        return array_reduce($this->lines, static fn (Money $sum, ReceiptQuoteLine $l): Money => $sum->plus($l->lineValue), Money::zero());
    }

    public function landedTotal(): Money
    {
        return $this->freight->plus($this->customsDuty)->plus($this->otherCost);
    }

    /** What the supplier is owed for this delivery. */
    public function payableAmount(): Money
    {
        return $this->costsPaidToSupplier ? $this->goodsValue()->plus($this->landedTotal()) : $this->goodsValue();
    }

    /** Whether the PO is fully received once this receipt is written (lines not in the receipt count too). */
    public function completesPurchase(int $outstandingElsewhere): bool
    {
        foreach ($this->lines as $line) {
            if ($line->quantityOutstandingAfter() > 0) {
                return false;
            }
        }

        return $outstandingElsewhere === 0;
    }
}
