<?php

declare(strict_types=1);

use App\Http\Controllers\Web\PublicMediaController;
use App\Http\Controllers\Web\SiteAssetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| There is deliberately no Blade storefront here. The customer-facing shop is
| the Next.js app in toymartbd-app/storefront, and the operations dashboard is
| the React app in toymartbd-app/admin. Both consume /api/v1/*.
|
| Blade is used only for invoice PDFs and transactional mail.
|
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'docs' => url('/api/docs'),
    'admin' => url('/admin'),
    'health' => url('/up'),
]));

/*
| Stable public media URL (docs/api/media-library.md, decision 3): a short-cache 302 to
| the current rendition, so a copied link survives Replace.
*/
Route::get('/m/{media}/{filename}', PublicMediaController::class)
    ->where(['media' => '[0-9]+', 'filename' => '[^/]+'])
    ->middleware('throttle:240,1')
    ->name('media.public');

/*
| Site & branding files for the storefront's <head> (Store settings › Site & branding): favicon
| and app-icon PNGs, the share-image JPEG. Generated once per image, then a short-cache 302.
*/
Route::get('/site-assets/{media}/{name}', SiteAssetController::class)
    ->where(['media' => '[0-9]+', 'name' => 'icon-(?:32|180|192|512)\.png|share\.jpg'])
    ->middleware('throttle:240,1')
    ->name('site-assets');

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
