<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\DTOs;

use App\Support\Money;

final readonly class CourierQuote
{
    public function __construct(
        public Money $charge,
        public Money $codFee,
        public ?int $etaDays = null,
    ) {}
}
