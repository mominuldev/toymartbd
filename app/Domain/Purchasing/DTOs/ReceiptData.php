<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTOs;

use App\Domain\Purchasing\Enums\LandedCostMethod;
use App\Support\Money;

/**
 * A delivery to receive (or quote): units per PO line, the stock location, and the
 * landed costs to apportion. `lines` lists units arriving now per PO line, in the
 * order the request sent them (errors name `lines.N`); zero-unit lines are ignored.
 */
final readonly class ReceiptData
{
    /** @param  list<array{purchase_item_id: int, quantity: int}>  $lines */
    public function __construct(
        public array $lines,
        public Money $freight,
        public Money $customsDuty,
        public Money $otherCost,
        public LandedCostMethod $method = LandedCostMethod::Value,
        public ?int $locationId = null,
        public bool $costsPaidToSupplier = true,
        public ?string $note = null,
        /** Who was paid the landed costs when not the supplier (freight forwarder, C&F agent). */
        public ?string $costsVendor = null,
        public ?string $costsReference = null,
        /** How the third party was paid (an expense payment method); null = not paid yet. */
        public ?string $costsPaymentMethod = null,
    ) {}

    public function landedTotal(): Money
    {
        return $this->freight->plus($this->customsDuty)->plus($this->otherCost);
    }
}
