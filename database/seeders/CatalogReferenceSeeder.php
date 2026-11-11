<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\Seeder;

/**
 * REFERENCE DATA — runs in every environment, production included (DATABASE-DESIGN
 * §8). The common variant-defining attributes, Color and Size, with Bangla names
 * (rule 35). Age range and gender are product columns, not attributes, so they are
 * not seeded here.
 *
 * Idempotent: keyed on slug, and it only ever adds or renames — it never deletes a
 * value staff added, nor one a variant uses. Staff may edit these freely; a re-run
 * restores the reference names but leaves added values alone.
 */
class CatalogReferenceSeeder extends Seeder
{
    /**
     * @var list<array{name: string, name_bn: string, slug: string, type: AttributeType, values: list<array{0: string, 1: string, 2: string, 3?: string}>}>
     */
    private const array ATTRIBUTES = [
        [
            'name' => 'Color', 'name_bn' => 'রং', 'slug' => 'color', 'type' => AttributeType::Color,
            // [value, value_bn, slug, color_hex]
            'values' => [
                ['Red', 'লাল', 'red', '#E53935'],
                ['Blue', 'নীল', 'blue', '#1E88E5'],
                ['Green', 'সবুজ', 'green', '#43A047'],
                ['Yellow', 'হলুদ', 'yellow', '#FDD835'],
                ['Orange', 'কমলা', 'orange', '#FB8C00'],
                ['Pink', 'গোলাপি', 'pink', '#EC407A'],
                ['Purple', 'বেগুনি', 'purple', '#8E24AA'],
                ['Black', 'কালো', 'black', '#212121'],
                ['White', 'সাদা', 'white', '#FFFFFF'],
                ['Multicolor', 'বহুরঙা', 'multicolor', '#9E9E9E'],
            ],
        ],
        [
            'name' => 'Size', 'name_bn' => 'সাইজ', 'slug' => 'size', 'type' => AttributeType::Select,
            'values' => [
                ['Small', 'ছোট', 'small'],
                ['Medium', 'মাঝারি', 'medium'],
                ['Large', 'বড়', 'large'],
                ['Extra Large', 'অনেক বড়', 'extra-large'],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::ATTRIBUTES as $position => $definition) {
            $attribute = ProductAttribute::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'name_bn' => $definition['name_bn'],
                    'type' => $definition['type'],
                    'is_variant_defining' => true,
                    'is_filterable' => true,
                    'position' => $position,
                ],
            );

            foreach ($definition['values'] as $valuePosition => $value) {
                ProductAttributeValue::query()->updateOrCreate(
                    ['attribute_id' => $attribute->id, 'slug' => $value[2]],
                    [
                        'value' => $value[0],
                        'value_bn' => $value[1],
                        'color_hex' => $value[3] ?? null,
                        'position' => $valuePosition,
                    ],
                );
            }
        }
    }
}
