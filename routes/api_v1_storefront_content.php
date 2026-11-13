<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\Content\BannerController;
use App\Http\Controllers\Api\V1\Storefront\Content\MenuController;
use App\Http\Controllers\Api\V1\Storefront\Content\PageController;
use App\Http\Controllers\Api\V1\Storefront\Content\TestimonialController;
use Illuminate\Support\Facades\Route;

/*
| Storefront online-store content (Phase 7) — required from routes/api_v1_storefront.php inside
| its `storefront:read` group. Cache tags: content:pages, content:page:{slug}, content:menus,
| content:banners, content:testimonials (docs/api/revalidation.md).
*/

Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
Route::get('/pages/{slug}', [PageController::class, 'show'])->where('slug', '[a-z0-9-]{1,94}')->name('pages.show');
Route::get('/menus', [MenuController::class, 'index'])->name('menus.index');
Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
Route::get('/testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
