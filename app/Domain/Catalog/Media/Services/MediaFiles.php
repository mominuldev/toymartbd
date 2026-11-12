<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Media\Services;

use App\Domain\Catalog\Exceptions\InvalidCertificate;
use App\Domain\Catalog\Exceptions\InvalidImage;
use App\Domain\Catalog\Media\Enums\MediaKind;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\CertificateFiles;
use App\Domain\Catalog\Services\ImagePipeline;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores a media-library file, reusing the product pipeline unchanged:
 *
 * - an image goes through ImagePipeline (content sniff, payload scan, ≤ 10 MB and
 *   ≤ 8000 px, EXIF strip, re-encoded WebP master — the raw bytes are never kept);
 * - a PDF goes through CertificateFiles (sniffed by `%PDF-` + finfo, ≤ 10 MB, refused
 *   with JavaScript, launch actions, embedded files, RichMedia or XFA) and is stored
 *   privately as is.
 *
 * Refusals become a 422 on `file` with a localised message (lang/{en,bn}/media.php).
 * Disk writes only; the caller writes the row in its own short transaction (rule 4).
 */
final readonly class MediaFiles
{
    public function __construct(
        private ImagePipeline $pipeline,
        private CertificateFiles $certificates,
    ) {}

    /**
     * Sniff the kind from the bytes; anything that is not a PDF is treated as an image
     * candidate, so the pipeline's refusals apply to it.
     */
    public function kindOf(string $path): MediaKind
    {
        $head = (string) @file_get_contents($path, false, null, 0, 5);

        return $head === '%PDF-' ? MediaKind::Document : MediaKind::Image;
    }

    /**
     * Validate, then write the original under a new base.
     *
     * @return array{kind: MediaKind, base: string, mime: string, original_mime: string, width: int|null, height: int|null, original_size: int, master_bytes: int}
     *
     * @throws ValidationException 422 on `file`
     */
    public function store(string $path, string $prefix, ?MediaKind $expected = null): array
    {
        $kind = $this->kindOf($path);

        if ($expected !== null && $kind !== $expected) {
            throw ValidationException::withMessages(['file' => (string) __($expected === MediaKind::Image ? 'media.file.replace_image' : 'media.file.replace_document')]);
        }

        $size = (int) @filesize($path);
        $base = trim($prefix, '/').'/'.Str::lower((string) Str::ulid());
        $originals = Storage::disk(MediaUrls::originalsDisk());

        if ($kind === MediaKind::Document) {
            try {
                $this->certificates->inspect($path);
            } catch (InvalidCertificate $e) {
                throw self::refusal($e->reason, $e->params);
            }

            $originals->put("{$base}/".Media::DOCUMENT, (string) file_get_contents($path));

            return [
                'kind' => $kind, 'base' => $base, 'mime' => 'application/pdf', 'original_mime' => 'application/pdf',
                'width' => null, 'height' => null, 'original_size' => $size, 'master_bytes' => $size,
            ];
        }

        try {
            $info = $this->pipeline->inspect($path);
            $master = $this->pipeline->master($path);
        } catch (InvalidImage $e) {
            throw self::refusal($e->reason, $e->params);
        }

        $originals->put("{$base}/".Media::MASTER, $master['bytes']);

        return [
            'kind' => $kind, 'base' => $base, 'mime' => 'image/webp', 'original_mime' => $info['mime'],
            'width' => $master['width'], 'height' => $master['height'], 'original_size' => $size,
            'master_bytes' => strlen($master['bytes']),
        ];
    }

    /** Remove a base from both disks (a failed transaction's leftovers). */
    public static function discard(string $base): void
    {
        Storage::disk(MediaUrls::originalsDisk())->deleteDirectory($base);
        Storage::disk(MediaUrls::disk())->deleteDirectory($base);
    }

    /**
     * A safe display name: the client's basename, ASCII-folded, extension kept.
     */
    public static function displayName(string $clientName, MediaKind $kind): string
    {
        $stem = pathinfo($clientName, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $safe = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', Str::ascii($stem));
        $safe = trim((string) preg_replace('/-{2,}/', '-', $safe), '-.');
        $safe = mb_substr($safe === '' ? ($kind === MediaKind::Image ? 'image' : 'document') : $safe, 0, 180);

        if ($kind === MediaKind::Document) {
            return $safe.'.pdf';
        }

        return $safe.'.'.(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'avif'], true) ? $ext : 'webp');
    }

    /**
     * The library name for a product photo with no client filename (seeded or
     * backfilled rows). Never derived from the product name or slug, which often carry a
     * brand (media-library lead decision 5): `product-12-photo-02.webp`.
     */
    public static function productFallbackName(int $productId, int $position): string
    {
        return sprintf('product-%d-photo-%02d.webp', $productId, $position + 1);
    }

    /** @param array<string, string|int> $params */
    private static function refusal(string $reason, array $params): ValidationException
    {
        $key = "media.file.{$reason}";
        $message = __($key, $params);

        return ValidationException::withMessages(['file' => is_string($message) && $message !== $key ? $message : (string) __('media.file.wrong_type')]);
    }
}
