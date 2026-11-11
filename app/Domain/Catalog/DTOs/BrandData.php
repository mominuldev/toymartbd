<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/** Input for CreateBrand. A null slug means "generate one from the names". */
final readonly class BrandData
{
    public function __construct(
        public string $name,
        public ?string $nameBn = null,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $descriptionBn = null,
        public bool $isActive = true,
    ) {}
}
