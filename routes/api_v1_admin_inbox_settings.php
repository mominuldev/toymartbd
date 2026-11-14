<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Settings\InboxChannelSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Integrations › Messaging › inbox channels: WhatsApp Cloud API and Messenger credentials.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|
| Read and write: settings.manage_integrations (like /settings/messaging). docs/api/inbox.md.
*/

Route::get('/settings/inbox-channels', [InboxChannelSettingsController::class, 'show'])->name('settings.inbox-channels.show');
Route::put('/settings/inbox-channels', [InboxChannelSettingsController::class, 'update'])->name('settings.inbox-channels.update');
