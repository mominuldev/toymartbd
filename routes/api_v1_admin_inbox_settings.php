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
// Test connection: one read-only Graph API call; rate-limited per user, like the SMS test send.
Route::post('/settings/inbox-channels/{channel}/test', [InboxChannelSettingsController::class, 'test'])
    // Any word here, so a guest gets the 401 before routing can 404; the request 404s an unknown channel.
    ->where('channel', '[a-z_]{1,20}')
    ->middleware('throttle:5,1')
    ->name('settings.inbox-channels.test');

// "Continue with Facebook" (Inbox › Channels › Facebook Page): start, finish, connect the chosen Page.
Route::post('/settings/inbox-channels/messenger/login', [InboxChannelSettingsController::class, 'startLogin'])
    ->middleware('throttle:10,1')
    ->name('settings.inbox-channels.messenger.login');
Route::post('/settings/inbox-channels/messenger/login/finish', [InboxChannelSettingsController::class, 'finishLogin'])
    ->middleware('throttle:10,1')
    ->name('settings.inbox-channels.messenger.login.finish');
Route::post('/settings/inbox-channels/messenger/connect', [InboxChannelSettingsController::class, 'connect'])
    ->middleware('throttle:10,1')
    ->name('settings.inbox-channels.messenger.connect');
// Disconnect a Page / number: clears the saved account and token, replies off.
Route::post('/settings/inbox-channels/{channel}/disconnect', [InboxChannelSettingsController::class, 'disconnect'])
    ->where('channel', '[a-z_]{1,20}')
    ->name('settings.inbox-channels.disconnect');
