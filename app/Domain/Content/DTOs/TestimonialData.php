<?php

declare(strict_types=1);

namespace App\Domain\Content\DTOs;

/** The whole testimonial (a save replaces it). A null position on create appends it. */
final readonly class TestimonialData
{
    public function __construct(
        public string $name,
        public string $quote,
        public ?string $nameBn = null,
        public ?string $location = null,
        public ?string $locationBn = null,
        public ?string $quoteBn = null,
        public ?int $rating = null,
        public ?int $mediaId = null,
        public bool $isPublished = false,
    ) {}
}
