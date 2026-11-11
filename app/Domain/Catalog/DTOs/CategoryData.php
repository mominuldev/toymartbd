<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

/** Input for CreateCategory. A null slug means "generate one from the names"; a null parent makes a root. */
final readonly class CategoryData
{
    public function __construct(
        public string $name,
        public ?string $nameBn = null,
        public ?string $slug = null,
        public ?int $parentId = null,
        public ?string $description = null,
        public ?string $descriptionBn = null,
        public ?string $icon = null,
        public bool $isFeatured = false,
        public bool $showInMenu = true,
        public bool $isActive = true,
    ) {}
}
