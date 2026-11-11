<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Brand;
use App\Support\Slug;

/**
 * Brand slug generation: ASCII, transliterated from the English name or, failing
 * that, the Bangla one (rule 37), then made unique ("lego", "lego-2" …).
 * No transaction of its own — it composes inside an Action.
 */
final class BrandSlugs
{
    public function generate(?string $name, ?string $nameBn): string
    {
        $base = rtrim(substr(Slug::forNames($name, $nameBn), 0, Brand::SLUG_BASE_MAX), '-');

        return Slug::unique($base, static fn (string $candidate): bool => Brand::query()->where('slug', $candidate)->exists());
    }
}
