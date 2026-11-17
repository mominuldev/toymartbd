<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\ProductCopyController;
use Illuminate\Support\Facades\Route;

// Product › Write with AI (Figma 538:82911). A draft only — Save product saves (catalog.update).
Route::get('/products/{product}/ai-copy/facts', [ProductCopyController::class, 'facts'])->name('products.ai-copy.facts');
Route::post('/products/{product}/ai-copy', [ProductCopyController::class, 'store'])->name('products.ai-copy');
