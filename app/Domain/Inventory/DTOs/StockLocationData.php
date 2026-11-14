<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use App\Domain\Inventory\Enums\StockLocationType;

/**
 * One save of a branch or warehouse from Store settings › Branches & warehouses. The
 * address follows rule 31; the division is not sent — it is the district's. `code` is set
 * once, at creation (inventory screens and filters show it as the short label).
 */
final readonly class StockLocationData
{
    public function __construct(
        public string $name,
        public ?string $nameBn,
        public StockLocationType $type,
        public int $districtId,
        public ?int $upazilaId,
        public ?string $area,
        public string $addressLine,
        public ?string $landmark,
        public ?string $notes,
        public bool $isActive,
        public ?string $code = null,
    ) {}
}
