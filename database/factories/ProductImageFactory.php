<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Image rows with no file (`path` null) until the media pipeline (P4-CAT-07).
 *
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'variant_id' => null,
            'path' => null,
            'alt_text' => fake()->sentence(4),
            'alt_text_bn' => 'খেলনার ছবি',
            'position' => 0,
            'is_primary' => false,
        ];
    }
}
