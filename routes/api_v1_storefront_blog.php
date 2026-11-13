<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\Blog\BlogController;
use Illuminate\Support\Facades\Route;

/*
| Storefront blog reads (Phase 7) — required from routes/api_v1_storefront.php inside the
| `storefront:read` token group. Cached by the Next.js server under `content:blog` /
| `content:post:{id}` (docs/api/revalidation.md); the preview route is never cached.
*/

Route::prefix('blog')->name('blog.')->group(function (): void {
    Route::get('/posts', [BlogController::class, 'index'])->name('posts.index');
    Route::get('/teaser', [BlogController::class, 'teaser'])->name('teaser');
    Route::get('/posts/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]{1,94}')->name('posts.show');
    Route::get('/preview/{token}', [BlogController::class, 'preview'])->where('token', '[a-z_.0-9a-f]{1,200}')->middleware('throttle:60,1')->name('preview');
    Route::get('/categories', [BlogController::class, 'categories'])->name('categories.index');
    Route::get('/categories/{slug}', [BlogController::class, 'category'])->where('slug', '[a-z0-9-]{1,94}')->name('categories.show');
    Route::get('/tags/{slug}', [BlogController::class, 'tag'])->where('slug', '[a-z0-9-]{1,94}')->name('tags.show');
    Route::get('/authors/{slug}', [BlogController::class, 'author'])->where('slug', '[a-z0-9-]{1,94}')->name('authors.show');
});
