<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\DTOs;

use App\Domain\Catalog\Enums\AgeBand;
use App\Domain\Content\Blog\Enums\PostStatus;
use App\Domain\Content\Blog\Enums\PostType;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The whole post document, as the admin editor saves it (a save replaces it).
 * `publishAt` is a UTC instant (the request converts the editor's Dhaka time).
 * `sections` matter only for a buying guide. `seo` null = keep the current seo_meta; otherwise
 * only the seo_meta columns it names change (the SEO module's full panel owns the rest).
 */
final readonly class BlogPostData
{
    /**
     * @param  list<int>  $tagIds
     * @param  list<GuideSectionData>  $sections
     * @param  list<int>  $relatedProductIds
     * @param  array<string, mixed>|null  $seo  seo_meta column => value
     */
    public function __construct(
        public PostType $type,
        public string $title,
        public ?string $titleBn,
        public ?string $slug,
        public ?string $excerpt,
        public ?string $excerptBn,
        public ?string $body,
        public ?string $bodyBn,
        public ?int $coverMediaId,
        public ?int $authorId,
        public ?int $categoryId,
        public array $tagIds,
        public PostStatus $status,
        public ?CarbonImmutable $publishAt,
        public bool $isFeatured,
        public ?AgeBand $guideAgeBand = null,
        public ?Money $guideBudgetMin = null,
        public ?Money $guideBudgetMax = null,
        public array $sections = [],
        public array $relatedProductIds = [],
        public ?array $seo = null,
    ) {}
}
