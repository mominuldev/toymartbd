<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalog\DTOs\ProductData;
use App\Domain\Catalog\DTOs\VariantData;
use App\Domain\Catalog\DTOs\VariantDefaultsData;
use App\Domain\Catalog\DTOs\VariantOptionData;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Support\Money;

/**
 * Catalog fixtures for product tests: a Color attribute (3 values) and a Size
 * attribute (4 values), plus ProductData builders.
 */
final class CatalogFixtures
{
    /**
     * @return array{color: ProductAttribute, colors: list<ProductAttributeValue>, size: ProductAttribute, sizes: list<ProductAttributeValue>}
     */
    public static function colorAndSize(): array
    {
        $color = ProductAttribute::factory()->color()->create(['name' => 'Color', 'name_bn' => 'রং', 'slug' => 'color', 'position' => 0]);
        $size = ProductAttribute::factory()->create(['name' => 'Size', 'name_bn' => 'সাইজ', 'slug' => 'size', 'position' => 1]);

        $colors = [];
        foreach ([['Red', 'লাল', 'red'], ['Blue', 'নীল', 'blue'], ['Green', 'সবুজ', 'green']] as $i => [$value, $bn, $slug]) {
            $colors[] = ProductAttributeValue::factory()->create(['attribute_id' => $color->id, 'value' => $value, 'value_bn' => $bn, 'slug' => $slug, 'position' => $i]);
        }

        $sizes = [];
        foreach ([['Small', 'ছোট', 's'], ['Medium', 'মাঝারি', 'm'], ['Large', 'বড়', 'l'], ['Extra Large', 'অনেক বড়', 'xl']] as $i => [$value, $bn, $slug]) {
            $sizes[] = ProductAttributeValue::factory()->create(['attribute_id' => $size->id, 'value' => $value, 'value_bn' => $bn, 'slug' => $slug, 'position' => $i]);
        }

        return ['color' => $color, 'colors' => $colors, 'size' => $size, 'sizes' => $sizes];
    }

    /**
     * @param  array<string, mixed>  $overrides  named ProductData arguments
     */
    public static function simple(array $overrides = []): ProductData
    {
        $category = $overrides['primaryCategoryId'] ?? Category::factory()->create()->id;

        return new ProductData(...[
            'name' => 'Wooden Stacking Rings',
            'nameBn' => 'কাঠের রিং স্ট্যাক',
            'type' => ProductType::Simple,
            'primaryCategoryId' => $category,
            'categoryIds' => [$category],
            'status' => ProductStatus::Active,
            'ageMinMonths' => 12,
            'ageMaxMonths' => 36,
            'variants' => [new VariantData(price: Money::of('850'), costPrice: Money::of('520'), weightGrams: 400)],
            ...$overrides,
        ]);
    }

    /**
     * @param  list<ProductAttributeValue>  $colors
     * @param  list<ProductAttributeValue>  $sizes
     * @param  array<string, mixed>  $overrides  named ProductData arguments
     */
    public static function variable(array $colors, array $sizes, array $overrides = []): ProductData
    {
        $category = $overrides['primaryCategoryId'] ?? Category::factory()->create()->id;

        $options = [];
        if ($colors !== []) {
            $options[] = new VariantOptionData($colors[0]->attribute_id, array_map(static fn ($v): int => $v->id, $colors));
        }
        if ($sizes !== []) {
            $options[] = new VariantOptionData($sizes[0]->attribute_id, array_map(static fn ($v): int => $v->id, $sizes));
        }

        return new ProductData(...[
            'name' => 'Kids Raincoat',
            'nameBn' => 'বাচ্চাদের রেইনকোট',
            'type' => ProductType::Variable,
            'primaryCategoryId' => $category,
            'categoryIds' => [$category],
            'status' => ProductStatus::Active,
            'options' => $options,
            'variantDefaults' => new VariantDefaultsData(price: Money::of('1450'), costPrice: Money::of('900'), weightGrams: 350),
            ...$overrides,
        ]);
    }
}
