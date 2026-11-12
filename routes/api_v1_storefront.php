<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\BrandController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CategoryController;
use App\Http\Controllers\Api\V1\Storefront\DeliveryController;
use App\Http\Controllers\Api\V1\Storefront\ProductController;
use App\Http\Controllers\Api\V1\Storefront\ProductDetailController;
use App\Http\Controllers\Api\V1\Storefront\SearchController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| Storefront API — v1
|--------------------------------------------------------------------------
|
| Consumed by the Next.js storefront from Server Components and Route Handlers
| using a server-side token. No API credential ever reaches a browser.
|
| Frozen on first production deploy: additive changes only. Breaking changes
| go to v2 with both served during migration.
|
| Endpoints are added in Phase 4 (docs/IMPLEMENTATION-PLAN.md §3).
|
*/

Route::get('/ping', fn () => ['ok' => true, 'audience' => 'storefront'])->name('ping');

// Catalog reads: the Next.js server's token, with the `storefront:read` ability.
// No token → 401 `unauthenticated`; a token without the ability → 403 `forbidden`.
Route::middleware(['auth:sanctum', CheckAbilities::class.':storefront:read'])->group(function (): void {
    Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    Route::get('/brands/{slug}', [BrandController::class, 'show'])->name('brands.show');
    // `tree` before `{slug}`: "tree" is a reserved category slug.
    Route::get('/categories/tree', [CategoryController::class, 'tree'])->name('categories.tree');
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    // Product search (P4-CAT-14) — docs/api/search.md. Autocomplete has its own, tighter limit.
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/search/suggest', [SearchController::class, 'suggest'])->middleware('throttle:search-suggest')->name('search.suggest');
    // Delivery charge and time (P4-STO-06) — DeliveryChargeResolver, the checkout code path.
    Route::get('/delivery-quote', [DeliveryController::class, 'quote'])->name('delivery.quote');
    Route::get('/delivery-rates', [DeliveryController::class, 'rates'])->name('delivery.rates');
    Route::get('/districts', [DeliveryController::class, 'districts'])->name('districts.index');
    Route::get('/districts/{district}/upazilas', [DeliveryController::class, 'upazilas'])->whereNumber('district')->name('districts.upazilas');
    Route::get('/products/{slug}', [ProductDetailController::class, 'show'])->name('products.show');
});

// Cart (P4-CRT-01/02/04) — docs/api/cart.md. The cart is found by the opaque token the
// Next.js server keeps in an httpOnly cookie and forwards as `X-Cart-Token`. Same server
// token and ability as the catalog reads (the storefront token is the only credential
// the Next.js server holds); every figure comes from the pricing pipeline.
Route::middleware(['auth:sanctum', CheckAbilities::class.':storefront:read'])->prefix('cart')->name('cart.')->group(function (): void {
    Route::post('/', [CartController::class, 'store'])->name('store');
    Route::get('/', [CartController::class, 'show'])->name('show');
    Route::post('/items', [CartController::class, 'addItem'])->name('items.store');
    Route::patch('/items/{item}', [CartController::class, 'updateItem'])->whereNumber('item')->name('items.update');
    Route::delete('/items/{item}', [CartController::class, 'removeItem'])->whereNumber('item')->name('items.destroy');
    Route::post('/coupon', [CartController::class, 'applyCoupon'])->name('coupon.store');
    Route::delete('/coupon', [CartController::class, 'removeCoupon'])->name('coupon.destroy');
});
