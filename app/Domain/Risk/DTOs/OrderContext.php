<?php

declare(strict_types=1);

namespace App\Domain\Risk\DTOs;

use App\Support\Money;

/** The order-level inputs to the score: only present when scoring an order, not a bare phone. */
final readonly class OrderContext
{
    public function __construct(
        public Money $orderValue,
        public ?string $addressLine = null,
        /** The address names an upazila or an area, not just a district. */
        public bool $hasLocality = true,
    ) {}
}
