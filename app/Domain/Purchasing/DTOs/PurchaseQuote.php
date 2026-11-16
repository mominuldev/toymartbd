<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Support\Money;

/**
 * A PO preview (the New purchase order rail): what CreatePurchase would write for these
 * lines, computed by the same PurchaseTotals (rule 1). Writes nothing.
 */
final readonly class PurchaseQuote
{
    /**
     * @param  list<array{variant_id: int, quantity: int, line_total: Money, last_unit_cost: string|null}>  $lines
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discount,
        public Money $total,
        public bool $supplierKnown,
    ) {}

    public function units(): int
    {
        return array_sum(array_column($this->lines, 'quantity'));
    }
}
