<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\LiveAnalyticsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Analytics › Live (Figma B · 24 premium pass 522:81629) — reports.view. No parameters: every
| window is the server's clock, so any query parameter is a 400.
|--------------------------------------------------------------------------
*/

Route::get('/analytics/live', [LiveAnalyticsController::class, 'index'])->name('analytics.live');
