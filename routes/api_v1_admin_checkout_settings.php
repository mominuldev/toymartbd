<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Store settings (Figma B · 38): Checkout & delivery rules.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
*/

// Read-only (settings.view). Writes go to PUT /settings/delivery (threshold) and the default
// courier's rate card — one source of truth each.
Route::get('/settings/checkout', [App\Http\Controllers\Api\V1\Admin\Settings\CheckoutSettingsController::class, 'show'])->name('settings.checkout.show');
