<?php

declare(strict_types=1);

namespace App\Domain\Catalog\DTOs;

use App\Domain\Catalog\Enums\RelationType;

/** A hand-curated recommendation pair from this product to another. */
final readonly class RelationData
{
    public function __construct(
        public int $relatedProductId,
        public RelationType $type,
        public int $position = 0,
        public ?string $reasonNote = null,
    ) {}
}
