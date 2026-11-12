<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Catalog\Media\Models\Media;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * `GET /m/{media}/{filename}` — the stable public URL "Copy URL" hands out
 * (docs/api/media-library.md, decision 3). A short-cache 302 to the current largest WebP
 * rendition, so the link survives Replace while rendition paths stay immutable. The
 * filename segment is cosmetic. Documents are private: 404.
 */
final class PublicMediaController extends Controller
{
    public function __invoke(string $media, string $filename): RedirectResponse|Response
    {
        $item = ctype_digit($media) ? Media::query()->find((int) $media) : null;
        $target = $item?->url();

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
