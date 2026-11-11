<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    protected $model = ProductAttributeValue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $value = ucfirst(fake()->unique()->word());

        return [
            'attribute_id' => ProductAttribute::factory(),
            'value' => $value,
            'value_bn' => 'মান '.fake()->unique()->numberBetween(1, 1_000_000),
            'slug' => Str::slug($value).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'color_hex' => null,
            'position' => 0,
        ];
    }

    public function colored(string $hex = '#E53935'): static
    {
        return $this->state(['color_hex' => $hex]);
    }
}
