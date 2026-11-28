<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Catalog\Services\VectorImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * `GET /m/{media}/{filename}` — the stable public URL "Copy URL" hands out
 * (docs/api/media-library.md, decision 3). The filename segment is cosmetic.
 * Documents are private: 404.
 *
 * - A raster image: a short-cache 302 to the current largest WebP rendition, so the link
 *   survives Replace while rendition paths stay immutable.
 * - An SVG: the sanitised file itself, and the ONLY way an SVG reaches a browser. It is
 *   sent with a `sandbox` Content-Security-Policy that forbids every script and network
 *   load, plus `nosniff`, so markup the sanitiser might miss still cannot run on our
 *   origin when the URL is opened directly. With `?v=` matching the current file
 *   (Media::vectorUrl) it is cached as immutable; otherwise briefly.
 */
final class PublicMediaController extends Controller
{
    /** No script, no remote fetch, no forms, no plugins — inline styles and data: images only. */
    public const string SVG_CSP = "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox";

    public function __invoke(Request $request, string $media, string $filename): RedirectResponse|Response
    {
        $item = ctype_digit($media) ? Media::query()->find((int) $media) : null;

        if ($item !== null && $item->isVector()) {
            return $this->vector($request, $item);
        }

        $target = $item?->url();

        if ($target === null) {
            return self::notFound();
        }

        return redirect()->away($target, 302, [
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function vector(Request $request, Media $item): Response
    {
        if ($item->url() === null) {
            return self::notFound();
        }

        $bytes = Storage::disk(MediaUrls::originalsDisk())->get($item->originalPath());

        if (! is_string($bytes) || $bytes === '') {
            return self::notFound();
        }

        $current = $request->query('v') === $item->version();

        return response($bytes, 200, [
            'Content-Type' => VectorImage::MIME,
            'Content-Security-Policy' => self::SVG_CSP,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$item->slugName().'.svg"',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
        ]);
    }

    private static function notFound(): Response
    {
        return response('Not found', 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=60',
        ]);
    }
}
