<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Inbox\InboxConversationController;
use App\Http\Controllers\Api\V1\Admin\Inbox\InboxMessageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Unified inbox (Figma B · 22): conversations, messages, assignment, notes, quick actions.
|--------------------------------------------------------------------------
| Required from routes/api_v1_admin.php inside its auth:sanctum + EnsureActiveStaffSession
| + EnsureTwoFactorEnrolled group; names are prefixed api.v1.admin. Every route needs a 403
| role-matrix test (tests/Support/role-matrix-routes.php) — rule 21.
|
| inbox.view: list, thread, messages, mark read. inbox.reply: reply, note, quick replies,
| assign, resolve, reopen, assignees. Policy: App\Policies\ConversationPolicy.
*/

Route::get('/inbox/conversations', [InboxConversationController::class, 'index'])->name('inbox.conversations.index');
Route::get('/inbox/assignees', [InboxConversationController::class, 'assignees'])->name('inbox.assignees');
Route::get('/inbox/conversations/{conversation}', [InboxConversationController::class, 'show'])->whereNumber('conversation')->name('inbox.conversations.show');
Route::post('/inbox/conversations/{conversation}/read', [InboxConversationController::class, 'read'])->whereNumber('conversation')->name('inbox.conversations.read');
Route::post('/inbox/conversations/{conversation}/assign', [InboxConversationController::class, 'assign'])->whereNumber('conversation')->name('inbox.conversations.assign');
Route::post('/inbox/conversations/{conversation}/resolve', [InboxConversationController::class, 'resolve'])->whereNumber('conversation')->name('inbox.conversations.resolve');
Route::post('/inbox/conversations/{conversation}/reopen', [InboxConversationController::class, 'reopen'])->whereNumber('conversation')->name('inbox.conversations.reopen');
Route::get('/inbox/conversations/{conversation}/messages', [InboxMessageController::class, 'index'])->whereNumber('conversation')->name('inbox.messages.index');
Route::post('/inbox/conversations/{conversation}/messages', [InboxMessageController::class, 'store'])->whereNumber('conversation')->name('inbox.messages.store');
Route::post('/inbox/conversations/{conversation}/notes', [InboxMessageController::class, 'note'])->whereNumber('conversation')->name('inbox.notes.store');
Route::post('/inbox/conversations/{conversation}/quick-replies', [InboxMessageController::class, 'quick'])->whereNumber('conversation')->name('inbox.quick-replies.store');
