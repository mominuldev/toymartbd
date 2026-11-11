<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Support\Slug;

/**
 * Product slug generation: ASCII, transliterated from the English name or, failing
 * that, the Bangla one (rule 37), made unique across all products — soft-deleted
 * ones included, because the column's UNIQUE index includes them.
 * No transaction of its own — it composes inside an Action.
 */
final class ProductSlugs
{
    public function generate(?string $name, ?string $nameBn): string
    {
        $base = rtrim(substr(Slug::forNames($name, $nameBn), 0, Product::SLUG_BASE_MAX), '-');

        return Slug::unique($base, static fn (string $candidate): bool => Product::withTrashed()->where('slug', $candidate)->exists());
    }
}
