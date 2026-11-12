<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\StockAdjustmentReason;

final readonly class StockAdjustmentData
{
    /**
     * @param  list<StockAdjustmentLine>  $lines  one per variant
     */
    public function __construct(
        public StockAdjustmentReason $reason,
        public array $lines,
        public ?int $locationId = null,
        public ?string $note = null,
    ) {}
}
