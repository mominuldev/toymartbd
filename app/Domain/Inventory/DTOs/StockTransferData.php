<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

final readonly class StockTransferData
{
    /**
     * @param  array<int, int>  $lines  variant id → units to move (> 0)
     */
    public function __construct(
        public int $fromLocationId,
        public int $toLocationId,
        public array $lines,
        public ?string $note = null,
    ) {}
}
