<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidImage;
use finfo;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * The media pipeline (P4-CAT-07, SYSTEM-ARCHITECTURE §11 "file upload abuse").
 *
 * 1. `inspect()` — sniff the real type from the bytes (finfo + a magic-byte check),
 *    never the extension or the client's MIME; refuse SVG, polyglots carrying a
 *    script/PHP payload, files over `media.max_bytes`, and images over
 *    `media.max_dimension` per side (read from the header, before any decode).
 * 2. `master()` — decode, apply EXIF orientation, optionally crop, cap the size, and
 *    re-encode to WebP with metadata stripped. Only this re-encoded master is ever
 *    stored; the raw upload bytes never leave the temp directory.
 * 3. `renditions()` — WebP and AVIF at each configured width the master reaches.
 *
 * Pure: takes and returns bytes. Writing to disks is the caller's job, so it can be
 * kept outside a database transaction (rule 4).
 */
final class ImagePipeline
{
    /** The master is kept at up to this width/height: 2× the largest rendition is plenty for re-crops. */
    public const int MASTER_MAX = 2400;

    /** Byte sequences that make an "image" a polyglot. Re-encoding would drop them anyway; refusing is louder. */
    private const array PAYLOAD_MARKERS = ['<?php', '<script', '<html', '<svg', '<!doctype', '<iframe'];

    /**
     * @return array{mime: string, width: int, height: int}
     *
     * @throws InvalidImage
     */
    public function inspect(string $path): array
    {
        $size = @filesize($path);

        if ($size === false || $size === 0) {
            throw new InvalidImage('The file is empty or unreadable.');
        }

        if ($size > $this->maxBytes()) {
            throw new InvalidImage(sprintf('The image is %.1f MB; the limit is %d MB.', $size / 1048576, intdiv($this->maxBytes(), 1048576)));
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = (string) @file_get_contents($path, false, null, 0, 32);

        if (! is_string($mime) || ! in_array($mime, $this->acceptedTypes(), true) || self::magicType($head) !== $mime) {
            throw new InvalidImage('Only JPEG, PNG, WebP or AVIF images are accepted.');
        }

        $bytes = (string) file_get_contents($path);
        $lower = strtolower($bytes);

        foreach (self::PAYLOAD_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                throw new InvalidImage('The file contains embedded script or markup and was refused.');
            }
        }

        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidImage('The image could not be read.');
        }

        $max = $this->maxDimension();

        if ($info[0] > $max || $info[1] > $max) {
            throw new InvalidImage("The image is {$info[0]}×{$info[1]} px; the limit is {$max} px per side.");
        }

        return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1]];
    }

    /**
     * Decode, orient, crop, cap and re-encode. `$crop` is in the oriented source's
     * pixels and is clamped to the image.
     *
     * @param  array{x: int, y: int, width: int, height: int}|null  $crop
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws InvalidImage
     */
    public function master(string $path, ?array $crop = null): array
    {
        $this->inspect($path);

        try {
            $image = $this->manager()->decodePath($path);

            if ($crop !== null) {
                $image = $this->crop($image, $crop);
            }

            $image->scaleDown(self::MASTER_MAX, self::MASTER_MAX);

            $bytes = $image->encode(new WebpEncoder(quality: $this->quality('master'), strip: true))->toString();
        } catch (InvalidImage $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidImage('The image could not be decoded.', previous: $e);
        }

        return ['bytes' => $bytes, 'width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * Re-crop an existing master (stored bytes).
     *
     * @param  array{x: int, y: int, width: int, height: int}  $crop
     * @return array{bytes: string, width: int, height: int}
     */
    public function recrop(string $masterBytes, array $crop): array
    {
        $image = $this->crop($this->manager()->decodeBinary($masterBytes), $crop);
        $bytes = $image->encode(new WebpEncoder(quality: $this->quality('master'), strip: true))->toString();

        return ['bytes' => $bytes, 'width' => $image->width(), 'height' => $image->height()];
    }

    /**
     * Every rendition of a master: `[width => [format => bytes]]`. Widths above the
     * master's own are skipped (never upscaled); a master narrower than the
     * smallest width yields one rendition at its own width.
     *
     * @param  list<int>|null  $widths
     * @return array<int, array<string, string>>
     */
    public function renditions(string $masterBytes, ?array $widths = null): array
    {
        $source = $this->manager()->decodeBinary($masterBytes);
        $sourceWidth = $source->width();

        $targets = array_values(array_filter($widths ?? MediaUrls::widths(), static fn (int $w): bool => $w <= $sourceWidth));

        if ($targets === []) {
            $targets = [$sourceWidth];
        }

        $out = [];

        foreach ($targets as $width) {
            $resized = (clone $source)->scaleDown(width: $width);

            foreach (MediaUrls::formats() as $format) {
                $encoder = match ($format) {
                    'avif' => new AvifEncoder(quality: $this->quality('avif'), strip: true),
                    default => new WebpEncoder(quality: $this->quality('webp'), strip: true),
                };

                $out[$width][$format] = $resized->encode($encoder)->toString();
            }
        }

        return $out;
    }

    /** The type the leading bytes declare, or null for anything else. */
    public static function magicType(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'image/webp',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true) => 'image/avif',
            default => null,
        };
    }

    /** @param array{x: int, y: int, width: int, height: int} $crop */
    private function crop(ImageInterface $image, array $crop): ImageInterface
    {
        $x = max(0, min($crop['x'], $image->width() - 1));
        $y = max(0, min($crop['y'], $image->height() - 1));
        $w = max(1, min($crop['width'], $image->width() - $x));
        $h = max(1, min($crop['height'], $image->height() - $y));

        return $image->crop($w, $h, $x, $y);
    }

    private function manager(): ImageManager
    {
        // A 40 MP decode needs ~160 MB in GD; give the decoder room without changing the global limit elsewhere.
        $limit = ini_get('memory_limit');

        if ($limit !== '-1' && self::bytes($limit) < 512 * 1048576) {
            @ini_set('memory_limit', '512M');
        }

        return new ImageManager(new GdDriver, autoOrientation: true, decodeAnimation: false, strip: true);
    }

    private static function bytes(string $value): int
    {
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1073741824,
            'm' => $number * 1048576,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function quality(string $key): int
    {
        $quality = config("media.quality.{$key}");

        return is_int($quality) ? $quality : 80;
    }

    private function maxBytes(): int
    {
        $max = config('media.max_bytes');

        return is_int($max) ? $max : 10 * 1048576;
    }

    private function maxDimension(): int
    {
        $max = config('media.max_dimension');

        return is_int($max) ? $max : 8000;
    }

    /** @return list<string> */
    private function acceptedTypes(): array
    {
        /** @var list<string> $types */
        $types = config('media.accepted_types', ['image/jpeg', 'image/png', 'image/webp', 'image/avif']);

        return $types;
    }
}
