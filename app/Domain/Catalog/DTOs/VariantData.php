<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\VariantStatus;
use App\Support\Money;

/**
 * One row of the variant matrix. Variable products identify the row by
 * `attributeValueIds` (one value per selected attribute); a simple product's single
 * row needs none.
 *
 * A null `sku`, `price`, `costPrice`, `weightGrams` or `status` means "keep" for an
 * existing variant and "use the default" for a new one (generated SKU,
 * `variantDefaults`, active). `costPrice` is null whenever the caller cannot see cost.
 * `barcode`, `compareAtPrice` and `wholesalePrice` are part of the document: null clears.
 */
final readonly class VariantData
{
    /**
     * @param  list<int>  $attributeValueIds
     */
    public function __construct(
        public array $attributeValueIds = [],
        public ?string $sku = null,
        public ?string $barcode = null,
        public ?Money $price = null,
        public ?Money $compareAtPrice = null,
        public ?Money $costPrice = null,
        public ?Money $wholesalePrice = null,
        public ?int $weightGrams = null,
        public ?VariantStatus $status = null,
    ) {}
}
