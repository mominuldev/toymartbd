<?php

declare(strict_types=1);

namespace App\Domain\Content\DTOs;

use App\Domain\Content\Enums\PageStatus;
use App\Domain\Content\Enums\PageTemplate;
use Carbon\CarbonImmutable;

/** The whole static-page document (a save replaces it). A null slug keeps the current one, or generates one. */
final readonly class CmsPageData
{
    /**
     * @param  list<array{question: string, question_bn: string|null, answer: string, answer_bn: string|null}>|null  $faqItems
     */
    public function __construct(
        public string $title,
        public ?string $titleBn = null,
        public ?string $slug = null,
        public ?string $content = null,
        public ?string $contentBn = null,
        public ?array $faqItems = null,
        public PageTemplate $template = PageTemplate::Default,
        public PageStatus $status = PageStatus::Draft,
        public ?CarbonImmutable $publishedAt = null,
    ) {}
}
