<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalog\Enums\ImageProcessingStatus;
use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use Illuminate\Support\Facades\Storage;

/**
 * The company logo on invoices, supplementary invoices and credit notes (P9 batch 1).
 *
 * At issue {@see self::reference()} copies a REFERENCE to one rendition file onto the
 * snapshot (`issued_by.logo` = media id, disk, path). Rendition paths carry a random id per
 * upload and are never rewritten (config/media.php), so a reprint draws exactly the file
 * that was current at issue — choosing another logo later changes only documents issued
 * after it. It is a database read only: nothing is fetched or copied inside the issuing
 * transaction.
 *
 * At render {@see self::localPath()} turns the reference into an absolute path on a LOCAL
 * disk, or null. mPDF is only ever handed a file that exists on this server — never a URL,
 * so rendering makes no HTTP request (rule 4's spirit). Null (no logo chosen, a document
 * issued before logos, a deleted file, or a non-local media disk) prints no image at all —
 * never a broken box.
 */
final class InvoiceLogo
{
    /** ~40 mm at 300 dpi; the nearest rendition at least this wide (else the largest). */
    public const int PRINT_WIDTH = 480;

    /**
     * @return array{media_id: int, disk: string, path: string}|null
     */
    public static function reference(?int $mediaId): ?array
    {
        if ($mediaId === null) {
            return null;
        }
        $media = Media::query()->find($mediaId);
        if (! $media instanceof Media || ! $media->isImage() || $media->processing_status !== ImageProcessingStatus::Ready) {
            return null;
        }
        $width = MediaUrls::pick($media->renditionWidths(), self::PRINT_WIDTH);
        if ($width === null) {
            return null;
        }

        return [
            'media_id' => $media->id,
            'disk' => MediaUrls::disk(),
            'path' => MediaUrls::renditionPath($media->base_path, $width, 'webp'),
        ];
    }

    /**
     * The snapshot's logo as an absolute local file path, or null.
     *
     * @param  array<string, mixed>|null  $issuedBy  an invoice's or credit note's `issued_by`
     */
    public static function localPath(?array $issuedBy): ?string
    {
        $logo = $issuedBy['logo'] ?? null;
        if (! is_array($logo) || ! is_string($logo['disk'] ?? null) || ! is_string($logo['path'] ?? null)) {
            return null;
        }
        $disk = $logo['disk'];
        $path = $logo['path'];
        // A relative path inside the disk, nothing else (no scheme, no traversal).
        if ($path === '' || str_contains($path, '..') || str_contains($path, '://') || str_starts_with($path, '/')) {
            return null;
        }
        if (config("filesystems.disks.{$disk}.driver") !== 'local') {
            return null;
        }
        $absolute = Storage::disk($disk)->path($path);

        return is_file($absolute) && is_readable($absolute) ? $absolute : null;
    }
}
