<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store settings › Delivery areas & timing: cut-off, ETA, metro/suburb districts, not-serviced upazilas, COD advance.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
*/

// Read: settings.view. Write: settings.update + shipments.manage_rates (UpdateDeliveryAreasRequest).
// `/settings/delivery` (the free-delivery threshold) is a separate, older endpoint.
Route::get('/settings/delivery-areas', [App\Http\Controllers\Api\V1\Admin\Settings\DeliveryAreasController::class, 'show'])->name('settings.delivery-areas.show');
Route::put('/settings/delivery-areas', [App\Http\Controllers\Api\V1\Admin\Settings\DeliveryAreasController::class, 'update'])->name('settings.delivery-areas.update');
