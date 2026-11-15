<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\Content\BannerController;
use App\Http\Controllers\Api\V1\Storefront\Content\MenuController;
use App\Http\Controllers\Api\V1\Storefront\Content\PageController;
use App\Http\Controllers\Api\V1\Storefront\Content\PageLayoutController;
use App\Http\Controllers\Api\V1\Storefront\Content\TestimonialController;
use Illuminate\Support\Facades\Route;

/*
| Storefront online-store content (Phase 7) — required from routes/api_v1_storefront.php inside
| its `storefront:read` group. Cache tags: content:pages, content:page:{slug}, content:menus,
| content:banners, content:testimonials (docs/api/revalidation.md).
*/

Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
Route::get('/pages/{slug}', [PageController::class, 'show'])->where('slug', '[a-z0-9-]{1,94}')->name('pages.show');
// Pages built from sections (2026-11-16). `preview` is declared first: it is not a slug.
Route::get('/content/pages/preview', [PageLayoutController::class, 'preview'])->name('content.pages.preview');
Route::get('/content/pages/{slug}', [PageLayoutController::class, 'show'])->where('slug', '[a-z0-9-]{1,94}')->name('content.pages.show');
Route::get('/menus', [MenuController::class, 'index'])->name('menus.index');
Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
Route::get('/testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
