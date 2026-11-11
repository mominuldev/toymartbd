<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\AttributeType;

/** Input for CreateAttribute. A null slug means "generate one from the names". */
final readonly class AttributeData
{
    public function __construct(
        public string $name,
        public ?string $nameBn = null,
        public ?string $slug = null,
        public AttributeType $type = AttributeType::Select,
        public bool $isVariantDefining = true,
        public bool $isFilterable = true,
    ) {}
}
