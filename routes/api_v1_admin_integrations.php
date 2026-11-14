<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\IntegrationsController;
use App\Models\Enums\Permission;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations hub (Figma B · 37): one status summary across every outside service — settings.manage_integrations.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
*/

Route::middleware('can:'.Permission::SettingsManageIntegrations->value)->group(function (): void {
    Route::get('/integrations', [IntegrationsController::class, 'index'])->name('integrations.index');
});
