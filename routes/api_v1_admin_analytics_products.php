<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Analytics › Products (Figma B · 24 premium pass 518:78370) — reports.view.
|--------------------------------------------------------------------------
*/

Route::get('/analytics/products', [App\Http\Controllers\Api\V1\Admin\ProductAnalyticsController::class, 'index'])->name('analytics.products');
