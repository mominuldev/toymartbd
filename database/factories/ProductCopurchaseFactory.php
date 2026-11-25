<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductCopurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One direction of a co-purchase pair. Tests that need both directions create two rows
 * (or run RebuildProductCopurchases over real orders).
 *
 * @extends Factory<ProductCopurchase>
 */
class ProductCopurchaseFactory extends Factory
{
    protected $model = ProductCopurchase::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'other_product_id' => Product::factory(),
            'orders_count' => 3,
            'last_ordered_at' => now()->subDay(),
            'computed_at' => now(),
        ];
    }
}
