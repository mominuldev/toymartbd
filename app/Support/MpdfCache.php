<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * mPDF's shared font cache (`tempDir`). mPDF builds its font metric files there on first use
 * with plain file writes, so two renders racing on a cold cache can read a half-written file and
 * produce a PDF whose text is missing — seen as intermittent empty-text PDFs under parallel
 * tests, and possible in production when two staff print at once right after a deploy. Every
 * render therefore holds an exclusive OS lock on the cache for its duration (renders are short
 * and infrequent: invoices, shipping labels, barcode labels).
 */
final class MpdfCache
{
    public static function path(): string
    {
        $dir = storage_path('framework/cache/mpdf');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /**
     * @template T
     *
     * @param  Closure(string): T  $render  receives the tempDir to pass to mPDF
     * @return T
     */
    public static function exclusive(Closure $render): mixed
    {
        $dir = self::path();
        $handle = fopen($dir.'/.render.lock', 'c');
        if ($handle === false) {
            throw new RuntimeException('Cannot open the mPDF cache lock.');
        }

        try {
            flock($handle, LOCK_EX);

            return $render($dir);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
