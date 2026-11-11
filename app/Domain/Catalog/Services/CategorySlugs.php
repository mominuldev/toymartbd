<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Support\Slug;

/**
 * Category slug generation, as for brands: ASCII from the English name or the
 * Bangla one (rule 37), then made unique. Soft-deleted rows still hold their slug
 * (the column is UNIQUE), so they count as taken.
 */
final class CategorySlugs
{
    public function generate(?string $name, ?string $nameBn): string
    {
        $base = rtrim(substr(Slug::forNames($name, $nameBn), 0, Category::SLUG_BASE_MAX), '-');

        return Slug::unique($base, static fn (string $candidate): bool => in_array($candidate, Category::RESERVED_SLUGS, true) || Category::withTrashed()->where('slug', $candidate)->exists());
    }
}
