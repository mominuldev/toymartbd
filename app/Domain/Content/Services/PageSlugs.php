<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Content\Models\CmsPage;
use App\Support\Slug;

/**
 * Static-page slugs: ASCII, transliterated from the English title or, failing that, the
 * Bangla one (rule 37), then made unique. No transaction of its own.
 */
final class PageSlugs
{
    public function generate(?string $title, ?string $titleBn): string
    {
        $base = rtrim(substr(Slug::forNames($title, $titleBn), 0, CmsPage::SLUG_BASE_MAX), '-');

        return Slug::unique($base, static fn (string $candidate): bool => CmsPage::query()->where('slug', $candidate)->exists());
    }
}
