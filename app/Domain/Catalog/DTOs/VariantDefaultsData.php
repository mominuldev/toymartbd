<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Support\Money;

/** Values for matrix combinations that have no row of their own. */
final readonly class VariantDefaultsData
{
    public function __construct(
        public ?Money $price = null,
        public ?Money $compareAtPrice = null,
        public ?Money $costPrice = null,
        public ?Money $wholesalePrice = null,
        public ?int $weightGrams = null,
    ) {}
}
