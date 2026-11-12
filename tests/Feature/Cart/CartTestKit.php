<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Shared fixtures for the cart, pricing and coupon tests (Bangla product names, rule 34). */
final class CartTestKit
{
    public const string HEADER = 'X-Cart-Token';

    public static function storefrontToken(): string
    {
        return User::factory()->create(['is_active' => false])->createToken('storefront', ['storefront:read'])->plainTextToken;
    }

    /**
     * A sellable variant with stock. Product gets a Bangla name; brand/category optional.
     *
     * @param  array<string, mixed>  $variant
     */
    public static function variant(string $price = '1000.00', int $stock = 10, ?Brand $brand = null, ?Category $category = null, array $variant = []): ProductVariant
    {
        $product = Product::factory()->withoutVariants()->create(array_filter([
            'name' => 'Remote Control Car',
            'name_bn' => 'রিমোট কন্ট্রোল গাড়ি',
            'brand_id' => $brand?->id,
            'primary_category_id' => $category?->id,
        ], static fn (mixed $v): bool => $v !== null));

        $model = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price' => $price,
            'weight_grams' => 300,
            'name_bn' => 'লাল',
            ...$variant,
        ]);

        if ($stock > 0) {
            self::stock($model, $stock);
        }

        return $model->refresh();
    }

    public static function stock(ProductVariant $variant, int $quantity): void
    {
        DB::transaction(fn () => app(StockLedger::class)->recordOne($variant->id, StockMovementType::OpeningBalance, $quantity));
    }
}
