<?php

declare(strict_types=1);

namespace App\Domain\Marketing\DTOs;

use App\Support\Money;

/**
 * One promotion or coupon that took money off, with its amount split across lines
 * (variant id → amount) so an order item can carry its own discount. The per-line
 * amounts always sum to `amount`. A free-shipping coupon has amount 0 and
 * `freeShipping = true`: its saving shows on the delivery line instead.
 */
final readonly class AppliedDiscount
{
    public const string PROMOTION = 'promotion';

    public const string COUPON = 'coupon';

    /** @param  array<int, Money>  $perLine */
    public function __construct(
        /** self::PROMOTION | self::COUPON */
        public string $source,
        public int $id,
        /** Promotion name or coupon code. */
        public string $label,
        public ?string $labelBn,
        public Money $amount,
        public array $perLine,
        public bool $freeShipping = false,
    ) {}
}
