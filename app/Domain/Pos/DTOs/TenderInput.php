<?php

declare(strict_types=1);

namespace App\Domain\Pos\DTOs;

use App\Domain\Payment\Enums\PaymentMethod;
use App\Support\Money;

/** One tender as the cashier entered it: the method, the amount handed over, its reference. */
final readonly class TenderInput
{
    public function __construct(
        public PaymentMethod $method,
        public Money $amount,
        public ?string $reference = null,
    ) {}
}
