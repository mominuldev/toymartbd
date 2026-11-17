<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\AiSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| AI for growth (owner request 2026-11-17, Figma 536:82212). Suggest only.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth + staff-session + 2FA group; names are
| prefixed api.v1.admin. Every route needs a 403 role-matrix test (rule 21). Each feature has
| its own file so parallel work does not collide.
*/

Route::get('/settings/ai', [AiSettingsController::class, 'show'])->name('settings.ai.show');
Route::put('/settings/ai', [AiSettingsController::class, 'update'])->name('settings.ai.update');
Route::put('/settings/ai/providers/{provider}', [AiSettingsController::class, 'saveKey'])->name('settings.ai.providers.update');
Route::delete('/settings/ai/providers/{provider}', [AiSettingsController::class, 'forgetKey'])->name('settings.ai.providers.destroy');
Route::post('/settings/ai/providers/{provider}/check', [AiSettingsController::class, 'check'])->name('settings.ai.providers.check');

require __DIR__.'/api_v1_admin_ai_advisor.php';
require __DIR__.'/api_v1_admin_ai_product_copy.php';
require __DIR__.'/api_v1_admin_ai_inbox.php';
require __DIR__.'/api_v1_admin_ai_restock.php';
