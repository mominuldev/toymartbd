<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Support\Money;

/**
 * One line of a receipt as the server computes it — the receive dialog shows exactly
 * these figures (rule 1), and ReceivePurchase writes them.
 */
final readonly class ReceiptQuoteLine
{
    public function __construct(
        public int $purchaseItemId,
        public int $variantId,
        public int $quantity,
        public int $quantityOrdered,
        public int $quantityReceivedBefore,
        /** Net of the line discount, 4 dp. */
        public string $unitCost,
        public Money $lineValue,
        public Money $allocatedCost,
        public string $landedUnitCost,
        public int $onHand,
        public string $avgCostBefore,
        public string $avgCostAfter,
    ) {}

    public function quantityOutstandingAfter(): int
    {
        return max(0, $this->quantityOrdered - $this->quantityReceivedBefore - $this->quantity);
    }
}
