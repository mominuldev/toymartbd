<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\Ai\RestockForecastController;
use Illuminate\Support\Facades\Route;

// AI for growth — Inventory › Restock forecast (Figma 540:83487). The forecast is server
// arithmetic; AI writes only the note. Draft POs go through the existing POST /purchases.
Route::get('/inventory/restock', [RestockForecastController::class, 'index'])->name('inventory.restock.index');
Route::post('/inventory/restock/note', [RestockForecastController::class, 'note'])->name('inventory.restock.note');
