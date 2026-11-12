<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\StockMovementType;

/**
 * One movement to record. `quantity` is the positive magnitude; the type carries the
 * sign. `referenceType`/`referenceId` point at the source document (Order, Purchase,
 * StockAdjustment, Shipment, Refund — DATABASE-DESIGN §3.2), or the Product for
 * opening stock.
 */
final readonly class StockMovementLine
{
    public function __construct(
        public int $variantId,
        public StockMovementType $type,
        public int $quantity,
        public ?string $unitCost = null,
        public ?string $referenceType = null,
        public ?int $referenceId = null,
        public ?int $userId = null,
        public ?string $note = null,
    ) {}
}
