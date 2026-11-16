<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Content\Services\SiteAssets;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * `GET /site-assets/{media}/{name}` — the storefront's favicons, app icons and share image, made
 * from the Store settings › Site & branding images ({@see SiteAssets}). A short-cache 302 to the
 * generated file, like `/m/{media}/…`. The media id in the path is the cache key: a new icon is a
 * new URL, and an id that is no longer the current icon / share image is a 404, so this route
 * never converts an arbitrary library image.
 */
final class SiteAssetController extends Controller
{
    public function __invoke(SiteAssets $assets, string $media, string $name): RedirectResponse|Response
    {
        $target = ctype_digit($media) ? $assets->url((int) $media, $name) : null;

        if ($target === null) {
            return response('Not found', 404, [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'public, max-age=60',
            ]);
        }

        return redirect()->away($target, 302, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
