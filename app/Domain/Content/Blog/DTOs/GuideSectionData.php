<?php

declare(strict_types=1);

namespace App\Domain\Content\Blog\DTOs;

use App\Domain\Catalog\Enums\AgeBand;
use App\Support\Money;

/** One buying-guide section; its body is raw editor HTML, sanitised by the Action. */
final readonly class GuideSectionData
{
    /** @param  list<GuidePickData>  $picks */
    public function __construct(
        public string $heading,
        public ?string $headingBn = null,
        public ?string $body = null,
        public ?string $bodyBn = null,
        public ?AgeBand $ageBand = null,
        public ?Money $budgetMin = null,
        public ?Money $budgetMax = null,
        public array $picks = [],
    ) {}
}
