<?php

declare(strict_types=1);

namespace App\Domain\Notification\DTOs;

/** An admin edit of one template (P4-NOT-03). Subject/preheader apply to email only. */
final readonly class TemplateData
{
    public function __construct(
        public string $bodyEn,
        public string $bodyBn,
        public bool $isActive,
        public ?string $subjectEn = null,
        public ?string $subjectBn = null,
        public ?string $preheaderEn = null,
        public ?string $preheaderBn = null,
        // Display names — custom templates only; ignored for system ones (P7).
        public ?string $nameEn = null,
        public ?string $nameBn = null,
    ) {}
}
