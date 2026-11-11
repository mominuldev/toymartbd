<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductRelation>
 */
class ProductRelationFactory extends Factory
{
    protected $model = ProductRelation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'related_product_id' => Product::factory(),
            'type' => RelationType::Related,
            'position' => 0,
            'reason_note' => null,
            'is_auto' => false,
        ];
    }
}
