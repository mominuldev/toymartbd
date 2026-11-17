<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\AiInboxDraftController;
use Illuminate\Support\Facades\Route;

// AI for growth — Inbox › Suggested reply (Figma 539:83211). inbox.reply, via ConversationPolicy::reply.
// Drafts only: nothing is sent or stored as a message.
Route::post('/inbox/conversations/{conversation}/ai-draft', [AiInboxDraftController::class, 'store'])->whereNumber('conversation')->name('inbox.ai-draft');
