<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/** Input for CreateAttributeValue. A null slug means "generate one from the value". */
final readonly class AttributeValueData
{
    public function __construct(
        public string $value,
        public ?string $valueBn = null,
        public ?string $slug = null,
        public ?string $colorHex = null,
    ) {}
}
