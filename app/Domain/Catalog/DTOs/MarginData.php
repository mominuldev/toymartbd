<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Support\Money;

/**
 * Gross margin of one unit at its current price and replacement cost, before delivery
 * and COD costs. `percent` is a one-decimal string ("39.5"); `profit` and `netPrice`
 * are taka. Computed by {@see \App\Domain\Catalog\Services\Margins}, never by a client.
 */
final readonly class MarginData
{
    public function __construct(
        public string $percent,
        public Money $profit,
        public Money $netPrice,
    ) {}

    /** @return array{percent: string, profit: string, net_price: string} */
    public function toArray(): array
    {
        return ['percent' => $this->percent, 'profit' => $this->profit->toDecimal(), 'net_price' => $this->netPrice->toDecimal()];
    }
}
