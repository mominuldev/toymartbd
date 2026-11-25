<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront\Recommendations;

use App\Domain\Catalog\Enums\RelationType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRelation;
use App\Domain\Catalog\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\CartTestKit;

/** Fixtures for the recommendation tests (P10): Bangla names on every product (rule 34). */
final class RecoKit
{
    /**
     * A live simple product: one default variant at `$price` with `$units` on hand.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function product(string $price = '1000.00', int $units = 10, array $attributes = []): Product
    {
        $product = Product::factory()->priced($price)->create(['name_bn' => 'খেলনা '.fake()->unique()->numberBetween(1, 1_000_000), ...$attributes]);
        if ($units > 0) {
            CartTestKit::stock($product->variants()->firstOrFail(), $units);
        }

        return $product;
    }

    /** A live product with two active variants, `$default` naming which one (if any) is the default. */
    public static function twoVariants(?int $default, int $units = 5): Product
    {
        $product = Product::factory()->variable()->create(['name_bn' => 'দুই রকম খেলনা']);
        foreach (['900.00', '1100.00'] as $i => $price) {
            $variant = ProductVariant::factory()->for($product)->create(['price' => $price, 'is_default' => $default === $i, 'position' => $i, 'name_bn' => 'রং '.$i]);
            CartTestKit::stock($variant, $units);
        }

        return $product;
    }

    public static function relate(Product $from, Product $to, RelationType $type, int $position = 0): void
    {
        ProductRelation::query()->create(['product_id' => $from->id, 'related_product_id' => $to->id, 'type' => $type, 'position' => $position]);
    }

    /** Both directions, like the projection (agent 2's RebuildProductCopurchases). */
    public static function copurchase(Product $a, Product $b, int $orders): void
    {
        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            DB::table('product_copurchases')->insert([
                'product_id' => $x->id, 'other_product_id' => $y->id, 'orders_count' => $orders,
                'last_ordered_at' => now()->subDay(), 'computed_at' => now(),
            ]);
        }
    }

    public static function category(): Category
    {
        return Category::factory()->create();
    }
}
