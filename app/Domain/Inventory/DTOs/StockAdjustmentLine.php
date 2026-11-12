<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

/**
 * One variant of a stock adjustment, given either as a signed change (`quantity`, e.g.
 * -2 for two damaged units) or as the counted on-hand after a stock count (`counted`).
 * Exactly one is set. A counted line's change is worked out by the server under the
 * row lock, never by the client from a figure it read earlier.
 */
final readonly class StockAdjustmentLine
{
    public function __construct(
        public int $variantId,
        public ?int $quantity = null,
        public ?int $counted = null,
    ) {}

    /** The signed change against the locked on_hand. */
    public function changeFrom(int $onHand): int
    {
        return $this->counted !== null ? $this->counted - $onHand : (int) $this->quantity;
    }
}
