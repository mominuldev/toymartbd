<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\Account\AccountController;
use App\Http\Controllers\Api\V1\Storefront\Account\AddressController as AccountAddressController;
use App\Http\Controllers\Api\V1\Storefront\Account\AuthController as AccountAuthController;
use App\Http\Controllers\Api\V1\Storefront\Account\OrderController as AccountOrderController;
use App\Http\Controllers\Api\V1\Storefront\Account\ReviewController as AccountReviewController;
use App\Http\Controllers\Api\V1\Storefront\Account\WishlistController as AccountWishlistController;
use App\Http\Controllers\Api\V1\Storefront\BrandController;
use App\Http\Controllers\Api\V1\Storefront\CartController;
use App\Http\Controllers\Api\V1\Storefront\CategoryController;
use App\Http\Controllers\Api\V1\Storefront\CheckoutController;
use App\Http\Controllers\Api\V1\Storefront\DeliveryController;
use App\Http\Controllers\Api\V1\Storefront\OrderController;
use App\Http\Controllers\Api\V1\Storefront\PaymentController;
use App\Http\Controllers\Api\V1\Storefront\ProductController;
use App\Http\Controllers\Api\V1\Storefront\ProductDetailController;
use App\Http\Controllers\Api\V1\Storefront\ReviewController;
use App\Http\Controllers\Api\V1\Storefront\SearchController;
use App\Http\Middleware\EnsureActiveCustomer;
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
    // Flash sales (P7): running sales with their products, and the next one.
    Route::get('/flash-sales', [App\Http\Controllers\Api\V1\Storefront\FlashSaleController::class, 'index'])->name('flash-sales.index');
    // Approved reviews + rating summary for the product page (P4-CAT-12).
    Route::get('/products/{slug}/reviews', [ReviewController::class, 'index'])->name('products.reviews.index');
    // SEO (P7, docs/api/seo.md §3): head data, the redirect map, sitemaps and robots.txt.
    Route::get('/seo/settings', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'settings'])->name('seo.settings');
    Route::get('/seo/{type}/{slug}', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'show'])->where(['type' => '[a-z_]{1,64}', 'slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->name('seo.show');
    Route::get('/redirects', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'redirects'])->name('redirects.index');
    Route::post('/redirects/{id}/hit', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'hit'])->whereNumber('id')->middleware('throttle:120,1')->name('redirects.hit');
    Route::get('/sitemaps/{file}', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'sitemap'])->where('file', 'sitemap[a-z0-9-]*\.xml')->name('sitemaps.show');
    Route::get('/robots', [App\Http\Controllers\Api\V1\Storefront\Seo\SeoController::class, 'robots'])->name('robots.show');
    // Blog and buying guides (Phase 7) — routes/api_v1_storefront_blog.php.
    require __DIR__.'/api_v1_storefront_blog.php';
    // Static pages, menus, banners, testimonials (Phase 7) — routes/api_v1_storefront_content.php.
    require __DIR__.'/api_v1_storefront_content.php';
    // CMS homepage blocks, published and draft preview (Phase 7) — routes/api_v1_storefront_cms_blocks.php.
    require __DIR__.'/api_v1_storefront_cms_blocks.php';
    // Server-side tracking config + browser-event relay (Phase 7 batch 2) — routes/api_v1_storefront_tracking.php.
    require __DIR__.'/api_v1_storefront_tracking.php';
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
    // Abandoned-cart recovery (P7, docs/api/cart-recovery.md): contact + consent, and the SMS link.
    Route::put('/contact', [App\Http\Controllers\Api\V1\Storefront\CartRecoveryController::class, 'contact'])->name('contact.update');
    Route::post('/restore', [App\Http\Controllers\Api\V1\Storefront\CartRecoveryController::class, 'restore'])->middleware('throttle:order-track')->name('restore');
});

// Checkout, confirmation and tracking (P4-ORD-02, P4-STO-09/10) — docs/api/checkout.md.
// Checkout needs an Idempotency-Key (rule 17; `idempotent` runs after auth, so the key is
// scoped to this token) and is limited per cart. Tracking needs number + phone (rule 22).
Route::middleware(['auth:sanctum', CheckAbilities::class.':storefront:read'])->group(function (): void {
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware(['throttle:checkout', 'idempotent'])->name('checkout.store');
    Route::post('/orders/track', [OrderController::class, 'track'])->middleware('throttle:order-track')->name('orders.track');
    Route::get('/orders/{number}', [OrderController::class, 'show'])->middleware('throttle:order-lookup')->where('number', '[A-Za-z0-9\-]{3,32}')->name('orders.show');
});

// Online payment (P4-PAY-02/03/04) — docs/api/checkout.md "Online payment". COD needs none of
// it. Starting/retrying a payment needs the placing cart's token and an Idempotency-Key
// (rule 17); the browser-return POST is signature-verified and re-queried, never trusted.
Route::middleware(['auth:sanctum', CheckAbilities::class.':storefront:read'])->group(function (): void {
    Route::get('/payment-methods', [PaymentController::class, 'methods'])->name('payment-methods.index');
    Route::post('/orders/{number}/payment', [PaymentController::class, 'start'])->middleware(['throttle:checkout', 'idempotent'])->where('number', '[A-Za-z0-9\-]{3,32}')->name('orders.payment.store');
    Route::post('/payments/sslcommerz/return', [PaymentController::class, 'sslcommerzReturn'])->middleware('throttle:checkout')->name('payments.sslcommerz.return');
});

// Customer sign-in (P4-STO-11) — docs/api/account.md. Phone + SMS code (never WhatsApp,
// rule 32b), called by the Next.js server with its storefront token. Limited per phone
// (3 codes / 10 min, 10 checks / 10 min) and per IP, on top of the Action's own
// 60-second resend cooldown, 5-codes-an-hour cap and 5-guesses-per-code burn.
Route::middleware(['auth:sanctum', CheckAbilities::class.':storefront:read'])->prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/otp', [AccountAuthController::class, 'requestOtp'])->middleware(['throttle:otp', 'throttle:otp-ip'])->name('otp.request');
    Route::post('/otp/verify', [AccountAuthController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
});

// The signed-in customer's account (P4-STO-11): the customer's own Sanctum token (guard
// `customer`, ability `customer`), which the Next.js server keeps in an httpOnly cookie.
// Every read and write is scoped to that customer.
Route::middleware(['auth:customer', CheckAbilities::class.':customer', EnsureActiveCustomer::class])->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', [AccountController::class, 'show'])->name('show');
    Route::patch('/', [AccountController::class, 'update'])->name('update');
    Route::post('/logout', [AccountAuthController::class, 'logout'])->name('logout');
    Route::get('/orders', [AccountOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{number}', [AccountOrderController::class, 'show'])->where('number', '[A-Za-z0-9\-]{3,32}')->name('orders.show');
    Route::get('/addresses', [AccountAddressController::class, 'index'])->name('addresses.index');
    Route::post('/addresses', [AccountAddressController::class, 'store'])->name('addresses.store');
    Route::patch('/addresses/{address}', [AccountAddressController::class, 'update'])->whereNumber('address')->name('addresses.update');
    Route::delete('/addresses/{address}', [AccountAddressController::class, 'destroy'])->whereNumber('address')->name('addresses.destroy');
    Route::post('/addresses/{address}/default', [AccountAddressController::class, 'makeDefault'])->whereNumber('address')->name('addresses.default');
    Route::get('/wishlist', [AccountWishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist', [AccountWishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('/wishlist/{product}', [AccountWishlistController::class, 'destroy'])->whereNumber('product')->name('wishlist.destroy');
    // Write a review (P4-CAT-12): pending until moderated; verification derived server-side.
    Route::post('/reviews', [AccountReviewController::class, 'store'])->middleware('throttle:reviews')->name('reviews.store');
});
