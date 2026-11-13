<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Domain\Purchasing\Enums\SupplierPaymentMethod;
use App\Support\Money;
use Carbon\CarbonImmutable;

/** A payment to record. `paidAt` is the Dhaka calendar date the money left. */
final readonly class SupplierPaymentData
{
    public function __construct(
        public Money $amount,
        public SupplierPaymentMethod $method,
        public CarbonImmutable $paidAt,
        public ?int $purchaseId = null,
        public ?string $reference = null,
        public ?string $note = null,
    ) {}
}
