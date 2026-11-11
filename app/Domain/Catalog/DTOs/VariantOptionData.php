<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/** The values of one variant-defining attribute selected for a variable product. */
final readonly class VariantOptionData
{
    /**
     * @param  list<int>  $valueIds
     */
    public function __construct(
        public int $attributeId,
        public array $valueIds,
    ) {}
}
