<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Inbox\InboxBadgeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inbox nav badge (owner decision 2026-11-15)
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. inbox.view
| (ConversationPolicy::viewAny). 403 role-matrix test: tests/Feature/Inbox/InboxBadgeTest.php.
*/

Route::get('/inbox/unread-count', [InboxBadgeController::class, 'show'])->name('inbox.unread-count');
