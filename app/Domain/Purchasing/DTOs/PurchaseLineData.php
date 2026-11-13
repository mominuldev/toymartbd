<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Support\Money;

/** One PO line as entered: `unitCost` is a decimal string (up to 4 dp). */
final readonly class PurchaseLineData
{
    public function __construct(
        public int $variantId,
        public int $quantity,
        public string $unitCost,
        public Money $discount,
    ) {}
}
