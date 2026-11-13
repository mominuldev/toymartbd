<?php

declare(strict_types=1);

namespace App\Domain\Content\DTOs;

use App\Domain\Content\Enums\BannerPlacement;
use Carbon\CarbonImmutable;

/** The whole banner (a save replaces it). Schedule instants are UTC. */
final readonly class BannerData
{
    public function __construct(
        public BannerPlacement $placement,
        public string $name,
        public ?string $title = null,
        public ?string $titleBn = null,
        public ?string $body = null,
        public ?string $bodyBn = null,
        public ?int $mediaId = null,
        public ?string $linkUrl = null,
        public ?string $linkLabel = null,
        public ?string $linkLabelBn = null,
        public ?int $categoryId = null,
        public int $priority = 0,
        public bool $isActive = true,
        public ?CarbonImmutable $startsAt = null,
        public ?CarbonImmutable $endsAt = null,
    ) {}
}
