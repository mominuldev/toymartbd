<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\BrandController;
use App\Http\Controllers\Api\V1\Storefront\CategoryController;
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
});
