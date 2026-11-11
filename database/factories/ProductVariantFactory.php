<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\VariantStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->withoutVariants(),
            'sku' => 'TEST-'.fake()->unique()->numberBetween(1, 99_999_999),
            'barcode' => null,
            'name' => null,
            'cost_price' => '800.00',
            'price' => '1250.00',
            'compare_at_price' => null,
            'wholesale_price' => null,
            'weight_grams' => 500,
            'is_default' => false,
            'position' => 0,
            'status' => VariantStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => VariantStatus::Archived]);
    }
}
