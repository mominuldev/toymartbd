<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Content\CmsHomeController;
use Illuminate\Support\Facades\Route;

/*
| Homepage builder (Phase 7) — docs/api/cms-blocks.md. Required from routes/api_v1_admin.php
| inside its auth:sanctum + EnsureActiveStaffSession group. Policies: CmsBlockPolicy,
| CmsLayoutVersionPolicy (content.view to read and preview, content.manage to change);
| role matrix in tests/Feature/Content/CmsBlocksRoleMatrixTest.php.
*/

Route::prefix('content/home')->name('content.home.')->group(function (): void {
    Route::get('/', [CmsHomeController::class, 'show'])->name('show');
    Route::post('/blocks', [CmsHomeController::class, 'storeBlock'])->name('blocks.store');
    Route::patch('/blocks/{block}', [CmsHomeController::class, 'updateBlock'])->whereNumber('block')->name('blocks.update');
    Route::delete('/blocks/{block}', [CmsHomeController::class, 'destroyBlock'])->whereNumber('block')->name('blocks.destroy');
    Route::post('/blocks/{block}/duplicate', [CmsHomeController::class, 'duplicateBlock'])->whereNumber('block')->name('blocks.duplicate');
    Route::put('/order', [CmsHomeController::class, 'reorder'])->name('order');
    Route::post('/publish', [CmsHomeController::class, 'publish'])->name('publish');
    Route::get('/versions', [CmsHomeController::class, 'versions'])->name('versions.index');
    Route::post('/versions/{version}/revert', [CmsHomeController::class, 'revert'])->whereNumber('version')->name('versions.revert');
    Route::post('/preview-token', [CmsHomeController::class, 'previewToken'])->name('preview-token');
});
