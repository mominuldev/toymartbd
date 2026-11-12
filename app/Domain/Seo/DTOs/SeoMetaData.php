<?php

declare(strict_types=1);

namespace App\Domain\Seo\DTOs;

use App\Domain\Seo\Enums\RobotsDirective;
use App\Domain\Seo\Enums\TwitterCard;

/** The whole `seo_meta` document for one page (a save replaces it). */
final readonly class SeoMetaData
{
    /** @param  array<string, mixed>|null  $schemaOverride */
    public function __construct(
        public ?string $title = null,
        public ?string $titleBn = null,
        public ?string $description = null,
        public ?string $descriptionBn = null,
        public ?string $canonicalUrl = null,
        public RobotsDirective $robots = RobotsDirective::IndexFollow,
        public bool $sitemapExclude = false,
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogImage = null,
        public TwitterCard $twitterCard = TwitterCard::SummaryLargeImage,
        public ?array $schemaOverride = null,
        public ?string $focusKeyword = null,
    ) {}
}
