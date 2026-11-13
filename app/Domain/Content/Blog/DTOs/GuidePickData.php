<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\DTOs;

/** One product pick inside a buying-guide section, with an optional "why we picked it". */
final readonly class GuidePickData
{
    public function __construct(
        public int $productId,
        public ?string $note = null,
        public ?string $noteBn = null,
    ) {}
}
