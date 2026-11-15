<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Support\Money;
use App\Support\PhoneNumber;

/**
 * A Charge as the register sent it. Every figure is re-derived on the server; `expectedTotal`
 * is only compared. `discount` is the cashier's discount as asked; the server prices it. `canSellOnDue`: the cashier holds `pos.sell_on_due` (read by the HTTP layer).
 */
final readonly class SaleData
{
    /**
     * @param  array<int, int>  $lines  variant id → quantity
     * @param  list<TenderInput>  $tenders
     */
    public function __construct(
        public int $sessionId,
        public array $lines,
        public array $tenders,
        public Money $expectedTotal,
        public int $cashierId,
        public ?string $couponCode = null,
        public ?PhoneNumber $phone = null,
        public ?string $customerName = null,
        public ?string $idempotencyKey = null,
        public bool $canSellOnDue = false,
        public ?DiscountInput $discount = null,
    ) {}
}
