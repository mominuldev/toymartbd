<?php

declare(strict_types=1);

namespace App\Domain\Finance\DTOs;

use App\Support\Money;

/**
 * One line of a courier remittance, as the COURIER reported it. `net` null = not on the
 * statement: computed as cod − charges − cod fee − return charge.
 */
final readonly class SettlementLine
{
    public function __construct(
        public int $lineNo,
        public string $consignmentId,
        public Money $codAmount,
        public Money $charges,
        public Money $codFee,
        public Money $returnCharge,
        public ?Money $net = null,
    ) {}

    public function netAmount(): Money
    {
        return $this->net ?? $this->codAmount->minus($this->charges)->minus($this->codFee)->minus($this->returnCharge);
    }
}
