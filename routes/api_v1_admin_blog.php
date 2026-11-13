<?php

declare(strict_types=1);

use App\Domain\Content\Blog\Models\BlogAuthor;
use App\Domain\Content\Blog\Models\BlogCategory;
use App\Domain\Content\Blog\Models\BlogTag;
use App\Http\Controllers\Api\V1\Admin\Blog\BlogPostController;
use App\Http\Controllers\Api\V1\Admin\Blog\BlogTermController;
use Illuminate\Support\Facades\Route;

/*
| Admin blog (Phase 7) — required from routes/api_v1_admin.php inside its auth:sanctum +
| EnsureActiveStaffSession group. Policies: BlogPostPolicy / BlogTermPolicy (content.view,
| content.manage); role matrix in tests/Feature/Content/Blog/BlogRoleMatrixTest.php.
*/

Route::model('blog_category', BlogCategory::class);
Route::model('blog_tag', BlogTag::class);
Route::model('blog_author', BlogAuthor::class);

Route::prefix('blog')->name('blog.')->group(function (): void {
    Route::get('/posts', [BlogPostController::class, 'index'])->name('posts.index');
    Route::post('/posts', [BlogPostController::class, 'store'])->name('posts.store');
    Route::get('/posts/{post}', [BlogPostController::class, 'show'])->whereNumber('post')->name('posts.show');
    Route::put('/posts/{post}', [BlogPostController::class, 'update'])->whereNumber('post')->name('posts.update');
    Route::delete('/posts/{post}', [BlogPostController::class, 'destroy'])->whereNumber('post')->name('posts.destroy');
    Route::post('/posts/{post}/preview-link', [BlogPostController::class, 'previewLink'])->whereNumber('post')->name('posts.preview-link');

    foreach (['categories' => 'blog_category', 'tags' => 'blog_tag', 'authors' => 'blog_author'] as $path => $param) {
        Route::get("/{$path}", [BlogTermController::class, 'index'])->name("{$path}.index");
        Route::post("/{$path}", [BlogTermController::class, 'store'])->name("{$path}.store");
        Route::get("/{$path}/{{$param}}", [BlogTermController::class, 'show'])->whereNumber($param)->name("{$path}.show");
        Route::patch("/{$path}/{{$param}}", [BlogTermController::class, 'update'])->whereNumber($param)->name("{$path}.update");
        Route::delete("/{$path}/{{$param}}", [BlogTermController::class, 'destroy'])->whereNumber($param)->name("{$path}.destroy");
    }
});
