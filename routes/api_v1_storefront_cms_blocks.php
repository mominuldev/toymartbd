<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Storefront\HomeContentController;
use Illuminate\Support\Facades\Route;

/*
| CMS homepage (Phase 7) — docs/api/cms-blocks.md. Required from routes/api_v1_storefront.php
| inside its auth:sanctum + storefront:read group. The preview additionally needs a signed
| preview token (X-Preview-Token) minted in the admin.
*/

Route::get('/content/home', [HomeContentController::class, 'home'])->name('content.home');
Route::get('/content/home/preview', [HomeContentController::class, 'preview'])->name('content.home.preview');
