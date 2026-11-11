<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Support\Slug;

/**
 * Slugs for attributes (globally unique) and their values (unique within the
 * attribute). Facet URLs use them (`?color=red`), so they are ASCII (rule 37).
 */
final class AttributeSlugs
{
    private const int BASE_MAX = 60;

    public function forAttribute(?string $name, ?string $nameBn): string
    {
        return Slug::unique(
            self::base($name, $nameBn),
            static fn (string $candidate): bool => ProductAttribute::query()->where('slug', $candidate)->exists(),
        );
    }

    public function forValue(int $attributeId, ?string $value, ?string $valueBn): string
    {
        return Slug::unique(
            self::base($value, $valueBn),
            static fn (string $candidate): bool => ProductAttributeValue::query()
                ->where('attribute_id', $attributeId)
                ->where('slug', $candidate)
                ->exists(),
        );
    }

    private static function base(?string $name, ?string $nameBn): string
    {
        return rtrim(substr(Slug::forNames($name, $nameBn), 0, self::BASE_MAX), '-');
    }
}
