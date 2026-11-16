<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Settings\BusinessDetailsController;
use App\Http\Controllers\Api\V1\Admin\Settings\SiteBrandingController;
use App\Http\Controllers\Api\V1\Admin\Settings\StoreLocationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store settings (Figma B · 38): Business details, Site & branding and Branches & warehouses.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|
| Reads need settings.view, writes settings.update. Business shares its storage with
| /settings/invoice; locations are StockLocation rows, never deleted.
*/

Route::get('/settings/business', [BusinessDetailsController::class, 'show'])->name('settings.business.show');
Route::put('/settings/business', [BusinessDetailsController::class, 'update'])->name('settings.business.update');

Route::get('/settings/site', [SiteBrandingController::class, 'show'])->name('settings.site.show');
Route::put('/settings/site', [SiteBrandingController::class, 'update'])->name('settings.site.update');

Route::get('/settings/locations', [StoreLocationController::class, 'index'])->name('settings.locations.index');
Route::get('/settings/locations/districts', [StoreLocationController::class, 'districts'])->name('settings.locations.districts');
Route::post('/settings/locations', [StoreLocationController::class, 'store'])->name('settings.locations.store');
Route::put('/settings/locations/{location}', [StoreLocationController::class, 'update'])->whereNumber('location')->name('settings.locations.update');
