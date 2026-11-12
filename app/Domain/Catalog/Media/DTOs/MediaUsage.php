<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\DTOs;

/**
 * One place that references a media row (docs/api/media-library.md `MediaUsage`).
 * Structured, so the admin composes the localised label itself.
 */
final readonly class MediaUsage
{
    public function __construct(
        public string $type,
        public string $subjectType,
        public int $subjectId,
        public string $title,
        public ?string $titleBn,
        public string $adminPath,
        public ?int $position = null,
        public ?bool $isPrimary = null,
    ) {}
}
