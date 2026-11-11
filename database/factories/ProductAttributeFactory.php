<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'name_bn' => 'বৈশিষ্ট্য '.fake()->unique()->numberBetween(1, 1_000_000),
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 1_000_000),
            'type' => AttributeType::Select,
            'is_variant_defining' => true,
            'is_filterable' => true,
            'position' => 0,
        ];
    }

    public function color(): static
    {
        return $this->state(['type' => AttributeType::Color]);
    }
}
