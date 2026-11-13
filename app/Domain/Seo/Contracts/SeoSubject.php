<?php

declare(strict_types=1);

namespace App\Domain\Seo\Contracts;

use Carbon\CarbonImmutable;

/**
 * One page the SEO module can describe: what its defaults fall back to, where it lives
 * and whether it is live. Built by a {@see SeoSubjectProvider}; plain values only, so a
 * domain that may not depend on Seo internals can build one (deptrac layer SeoContracts).
 *
 * `path` is locale-independent (`/products/lego-city`); Bangla is the same path under /bn.
 * `description*` are plain text. `image` is an absolute URL or null.
 */
final readonly class SeoSubject
{
    public function __construct(
        public string $type,
        public int $id,
        public string $slug,
        public string $path,
        public string $name,
        public ?string $nameBn,
        public ?string $description,
        public ?string $descriptionBn,
        public ?string $image,
        public bool $isLive,
        public ?CarbonImmutable $updatedAt,
    ) {}
}
