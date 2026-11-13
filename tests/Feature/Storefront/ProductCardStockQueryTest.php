<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductListing;
use App\Http\Resources\Storefront\StorefrontProductCardResource;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cart\CartTestKit;

// Phase 9 (docs/audits/PERFORMANCE.md P-10): cards built from products that were not read through
// ProductListing (search fallback, flash sale, home rails, wishlist, blog picks) resolve `in_stock`
// with ONE stock query for the whole list (primeStock, which every such list calls) — it used to
// be one per card.

it('resolves in_stock for a list of cards with one stock query', function (): void {
    $inStock = [CartTestKit::variant('500.00', 5), CartTestKit::variant('600.00', 3), CartTestKit::variant('700.00', 2)];
    $soldOut = [CartTestKit::variant('800.00', 0), CartTestKit::variant('900.00', 0)];
    $products = Product::query()->with(ProductListing::RELATIONS)->orderBy('id')->get();

    $stockQueries = 0;
    DB::listen(static function ($q) use (&$stockQueries): void {
        if (str_contains($q->sql, 'stock_levels')) {
            $stockQueries++;
        }
    });
    StorefrontProductCardResource::primeStock($products);
    $cards = StorefrontProductCardResource::collection($products)->resolve();

    expect($stockQueries)->toBe(1)
        ->and(collect($cards)->pluck('in_stock', 'id')->all())->toBe(
            array_fill_keys(array_map(static fn ($v) => $v->product_id, $inStock), true)
            + array_fill_keys(array_map(static fn ($v) => $v->product_id, $soldOut), false),
        );
});
