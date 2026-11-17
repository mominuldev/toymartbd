<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Analytics › Marketing ROI (Figma B · 24 premium pass 522:81016) — reading needs reports.view;
| typing ad spend in needs marketing.manage (AdSpendPolicy). Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|--------------------------------------------------------------------------
*/

Route::get('/analytics/marketing', [App\Http\Controllers\Api\V1\Admin\MarketingAnalyticsController::class, 'index'])->name('analytics.marketing');
Route::get('/analytics/marketing/spend', [App\Http\Controllers\Api\V1\Admin\AdSpendController::class, 'index'])->name('analytics.marketing.spend.index');
Route::post('/analytics/marketing/spend', [App\Http\Controllers\Api\V1\Admin\AdSpendController::class, 'store'])->name('analytics.marketing.spend.store');
Route::put('/analytics/marketing/spend/{adSpend}', [App\Http\Controllers\Api\V1\Admin\AdSpendController::class, 'update'])->whereNumber('adSpend')->name('analytics.marketing.spend.update');
Route::delete('/analytics/marketing/spend/{adSpend}', [App\Http\Controllers\Api\V1\Admin\AdSpendController::class, 'destroy'])->whereNumber('adSpend')->name('analytics.marketing.spend.destroy');
