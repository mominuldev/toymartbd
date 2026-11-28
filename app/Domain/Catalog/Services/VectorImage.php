<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidImage;
use enshrined\svgSanitize\Sanitizer;

/**
 * The SVG half of the media pipeline (owner decision 2026-11-28: keep SVG as a vector).
 *
 * An SVG is markup, so it is never trusted and never re-encoded to pixels:
 *
 * 1. `looksLikeSvg()` — the bytes, not the extension, say SVG: the root element after an
 *    optional BOM, XML declaration and comments is `<svg`.
 * 2. `sanitize()` — refuse a DOCTYPE or ENTITY outright (XXE, entity expansion), then run
 *    the allowlist sanitiser (enshrined/svg-sanitize) with remote references removed:
 *    scripts, event handlers, `foreignObject`, `javascript:` and external `href`/`url()`
 *    are dropped. The result is scanned again and refused if anything active survived.
 *    Only the sanitised bytes are ever stored.
 *
 * Serving is the other half: an SVG is kept on the PRIVATE originals disk and reaches a
 * browser only through `GET /m/{id}/{name}.svg` (PublicMediaController), which sends a
 * `sandbox` Content-Security-Policy and `nosniff` — so even a sanitiser miss cannot run
 * script on our origin.
 *
 * Pure: takes a path, returns bytes. Writing is the caller's job (rule 4).
 */
final class VectorImage
{
    public const string MIME = 'image/svg+xml';

    /** Anything here after sanitising means the sanitiser missed active content: refuse. */
    private const array ACTIVE_PATTERNS = [
        '/<\s*script\b/i',
        '/<\s*foreignObject\b/i',
        '/<\s*(?:iframe|embed|object|html|body)\b/i',
        '/\son[a-z]+\s*=/i',
        '/javascript\s*:/i',
        '/@import\b/i',
        '/<!ENTITY|<!DOCTYPE/i',
    ];

    public static function looksLikeSvg(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4096);
        $head = (string) preg_replace('/^\xEF\xBB\xBF/', '', $head);

        // Skip the XML declaration, comments and whitespace before the root element.
        $head = (string) preg_replace('/^\s*(?:<\?xml[^>]*\?>\s*)?(?:<!--.*?-->\s*)*/s', '', $head);

        return preg_match('/^<svg[\s>\/]/i', $head) === 1 || preg_match('/^<!DOCTYPE\s+svg/i', $head) === 1;
    }

    /**
     * @return array{bytes: string, width: int|null, height: int|null}
     *
     * @throws InvalidImage
     */
    public function sanitize(string $path): array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw new InvalidImage('The file is empty or unreadable.', 'empty');
        }

        $max = $this->maxBytes();

        if ($size > $max) {
            throw new InvalidImage(
                sprintf('The image is %.1f MB; the limit is %d MB.', $size / 1048576, intdiv($max, 1048576)),
                'too_large',
                ['size' => sprintf('%.1f', $size / 1048576), 'limit' => intdiv($max, 1048576)],
            );
        }

        $raw = (string) file_get_contents($path);

        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw) === 1) {
            throw new InvalidImage('The SVG declares a DOCTYPE or entities and was refused.', 'payload');
        }

        $sanitizer = new Sanitizer;
        $sanitizer->removeRemoteReferences(true);
        $sanitizer->removeXMLTag(true);
        $sanitizer->minify(false);

        $clean = $sanitizer->sanitize($raw);

        if (! is_string($clean) || trim($clean) === '' || preg_match('/<svg[\s>]/i', $clean) !== 1) {
            throw new InvalidImage('The SVG could not be read.', 'unreadable');
        }

        foreach (self::ACTIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $clean) === 1) {
                throw new InvalidImage('The file contains embedded script or markup and was refused.', 'payload');
            }
        }

        [$width, $height] = self::dimensions($clean);

        return ['bytes' => $clean, 'width' => $width, 'height' => $height];
    }

    /**
     * The intrinsic size from the root's `width`/`height` (unitless or px), else its
     * `viewBox`. Nulls when neither says.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function dimensions(string $svg): array
    {
        if (preg_match('/<svg\b([^>]*)>/is', $svg, $root) !== 1) {
            return [null, null];
        }

        $attr = static function (string $name) use ($root): ?string {
            return preg_match('/\s'.$name.'\s*=\s*(["\'])(.*?)\1/is', $root[1], $m) === 1 ? trim($m[2]) : null;
        };

        $length = static function (?string $value): ?int {
            if ($value === null || preg_match('/^(\d+(?:\.\d+)?)(?:px)?$/i', $value, $m) !== 1) {
                return null;
            }
            $n = (int) round((float) $m[1]);

            return $n > 0 ? $n : null;
        };

        $width = $length($attr('width'));
        $height = $length($attr('height'));

        if (($width === null || $height === null) && ($box = $attr('viewBox')) !== null) {
            $parts = preg_split('/[\s,]+/', $box);
            if (is_array($parts) && count($parts) === 4 && is_numeric($parts[2]) && is_numeric($parts[3])) {
                $w = (int) round((float) $parts[2]);
                $h = (int) round((float) $parts[3]);
                if ($w > 0 && $h > 0) {
                    // Keep the aspect ratio when only one side was given.
                    if ($width !== null) {
                        $height = (int) round($width * $h / $w);
                    } elseif ($height !== null) {
                        $width = (int) round($height * $w / $h);
                    } else {
                        [$width, $height] = [$w, $h];
                    }
                }
            }
        }

        return [$width, $height];
    }

    private function maxBytes(): int
    {
        $max = config('media.max_bytes');

        return is_int($max) ? $max : 10 * 1048576;
    }
}
