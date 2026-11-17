<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\GrowthAdvisorController;
use Illuminate\Support\Facades\Route;

// Analytics › Growth advisor (Figma 537:82561): weekly brief, suggested actions, Ask AI. reports.view.
Route::get('/analytics/advisor', [GrowthAdvisorController::class, 'show'])->name('analytics.advisor.show');
Route::post('/analytics/advisor/brief', [GrowthAdvisorController::class, 'refresh'])->name('analytics.advisor.brief');
Route::post('/analytics/advisor/ask', [GrowthAdvisorController::class, 'ask'])->name('analytics.advisor.ask');
Route::post('/analytics/advisor/actions/{action}/done', [GrowthAdvisorController::class, 'done'])->whereNumber('action')->name('analytics.advisor.actions.done');
Route::post('/analytics/advisor/actions/{action}/dismiss', [GrowthAdvisorController::class, 'dismiss'])->whereNumber('action')->name('analytics.advisor.actions.dismiss');
