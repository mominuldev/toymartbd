<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PublicMediaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| There is deliberately no Blade storefront here. The customer-facing shop is
| the Next.js app in its own repository (toymartbd-storefront), and the operations
| dashboard is the React admin in resources/js/admin. Both consume /api/v1/*.
|
| Blade is used only for invoice PDFs and transactional mail.
|
*/

/*
| The domain root opens the admin (owner decision 2026-11-17): signed out, the SPA's RequireAuth
| sends the visitor on to /admin/login; signed in, they land on the dashboard. A 302, not a 301,
| so a browser never caches it should the root later serve something else.
*/
Route::redirect('/', '/admin');

/*
| Stable public media URL (docs/api/media-library.md, decision 3): a short-cache 302 to
| the current rendition, so a copied link survives Replace.
*/
Route::get('/m/{media}/{filename}', PublicMediaController::class)
    ->where(['media' => '[0-9]+', 'filename' => '[^/]+'])
    ->middleware('throttle:240,1')
    ->name('media.public');

/*
| Admin SPA
|
| A React + Vite single-page app, built by Laravel's Vite and served from this
| catch-all so a client-side deep link like /admin/orders/1234 still resolves
| after a browser refresh. Auth is a Sanctum cookie session; the SPA reads data
| from /api/v1/admin/*.
|
| The 'auth' middleware is attached in Phase 3 (P3-API-05) once login exists.
*/
Route::view('/admin/{any?}', 'admin')
    ->where('any', '.*')
    ->name('admin');
