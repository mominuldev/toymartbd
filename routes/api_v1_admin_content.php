<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Content\BannerController;
use App\Http\Controllers\Api\V1\Admin\Content\MenuController;
use App\Http\Controllers\Api\V1\Admin\Content\PageController;
use App\Http\Controllers\Api\V1\Admin\Content\TestimonialController;
use Illuminate\Support\Facades\Route;

/*
| Admin online-store content (Phase 7): static pages, menus, banners, testimonials — required
| from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession group.
| Policies: CmsPagePolicy, MenuPolicy, BannerPolicy, TestimonialPolicy (content.view,
| content.manage); role matrix in tests/Feature/Content/StoreContentRoleMatrixTest.php.
*/

Route::prefix('content')->name('content.')->group(function (): void {
    Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
    Route::post('/pages', [PageController::class, 'store'])->name('pages.store');
    Route::get('/pages/{page}', [PageController::class, 'show'])->whereNumber('page')->name('pages.show');
    Route::put('/pages/{page}', [PageController::class, 'update'])->whereNumber('page')->name('pages.update');
    Route::delete('/pages/{page}', [PageController::class, 'destroy'])->whereNumber('page')->name('pages.destroy');

    Route::get('/menus', [MenuController::class, 'index'])->name('menus.index');
    Route::get('/menu-targets', [MenuController::class, 'targets'])->name('menus.targets');
    Route::get('/menus/{location}', [MenuController::class, 'show'])->name('menus.show');
    Route::put('/menus/{location}', [MenuController::class, 'update'])->name('menus.update');

    Route::get('/banners', [BannerController::class, 'index'])->name('banners.index');
    Route::post('/banners', [BannerController::class, 'store'])->name('banners.store');
    Route::get('/banners/{banner}', [BannerController::class, 'show'])->whereNumber('banner')->name('banners.show');
    Route::put('/banners/{banner}', [BannerController::class, 'update'])->whereNumber('banner')->name('banners.update');
    Route::delete('/banners/{banner}', [BannerController::class, 'destroy'])->whereNumber('banner')->name('banners.destroy');

    Route::get('/testimonials', [TestimonialController::class, 'index'])->name('testimonials.index');
    Route::post('/testimonials', [TestimonialController::class, 'store'])->name('testimonials.store');
    Route::post('/testimonials/reorder', [TestimonialController::class, 'reorder'])->name('testimonials.reorder');
    Route::get('/testimonials/{testimonial}', [TestimonialController::class, 'show'])->whereNumber('testimonial')->name('testimonials.show');
    Route::put('/testimonials/{testimonial}', [TestimonialController::class, 'update'])->whereNumber('testimonial')->name('testimonials.update');
    Route::delete('/testimonials/{testimonial}', [TestimonialController::class, 'destroy'])->whereNumber('testimonial')->name('testimonials.destroy');
});
