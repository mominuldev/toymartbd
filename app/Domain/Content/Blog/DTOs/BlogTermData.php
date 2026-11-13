<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\DTOs;

/**
 * A blog category, tag or author. `description*` is the category description or the
 * author bio; `position` applies to categories, `avatarMediaId` to authors.
 */
final readonly class BlogTermData
{
    public function __construct(
        public string $name,
        public ?string $nameBn = null,
        public ?string $slug = null,
        public ?string $description = null,
        public ?string $descriptionBn = null,
        public ?int $position = null,
        public ?int $avatarMediaId = null,
        public ?int $userId = null,
    ) {}
}
