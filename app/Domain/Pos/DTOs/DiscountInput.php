<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Domain\Pos\Enums\DiscountReason;

/**
 * A cashier's discount as the register sent it: `percent` of the goods (after promotions and
 * the coupon) or a flat `amount` in taka, with one of the fixed reasons. The server works out the taka and
 * checks it against the cap (PosQuotes); nothing here is trusted as money.
 */
final readonly class DiscountInput
{
    public const string PERCENT = 'percent';

    public const string AMOUNT = 'amount';

    public function __construct(
        public string $type,
        public string $value,
        public DiscountReason $reason,
    ) {}
}
