<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Reviews\DTOs;

/** A shopper's review as submitted. There is deliberately no "verified" flag here. */
final readonly class ReviewData
{
    public function __construct(
        public int $productId,
        public int $rating,
        public ?string $title,
        public ?string $body,
        public ?string $reviewerName,
        public ?int $orderItemId,
        public string $locale,
    ) {}
}
