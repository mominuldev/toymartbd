<?php

declare(strict_types=1);

namespace App\Domain\Seo\DTOs;

/** One manual redirect as the admin entered it (paths not yet normalised). */
final readonly class RedirectData
{
    public function __construct(
        public string $fromPath,
        public string $toPath,
        public int $statusCode = 301,
        public bool $isActive = true,
        public ?string $note = null,
    ) {}
}
