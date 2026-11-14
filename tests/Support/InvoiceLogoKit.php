<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Catalog\Media\Models\Media;
use App\Domain\Catalog\Services\MediaUrls;
use App\Domain\Invoicing\Services\CompanyDetails;
use App\Domain\Invoicing\Services\InvoiceLogo;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * P9: a library image with a real WebP rendition on the (faked, local) media disk, chosen as
 * the invoice logo in Settings → Invoice details.
 */
final class InvoiceLogoKit
{
    /** Fakes the media disk once per test; call before {@see self::choose()}. */
    public static function fakeDisk(): void
    {
        Storage::fake(MediaUrls::disk());
    }

    /** A ready image whose print rendition exists on disk (or not, with `$withFile` false). */
    public static function media(bool $withFile = true): Media
    {
        $media = Media::factory()->create();
        if ($withFile) {
            $width = MediaUrls::pick($media->renditionWidths(), InvoiceLogo::PRINT_WIDTH);
            Storage::disk(MediaUrls::disk())->put(MediaUrls::renditionPath($media->base_path, (int) $width, 'webp'), ImageFixtures::bytes('webp', 480, 160));
        }

        return $media;
    }

    public static function choose(?Media $media): void
    {
        if ($media === null) {
            Setting::query()->whereKey(CompanyDetails::PREFIX.'logo_media_id')->delete();

            return;
        }
        Setting::put(CompanyDetails::PREFIX.'logo_media_id', $media->id, SettingGroup::Store);
    }

    /** Embedded raster images in a PDF mPDF wrote (a logo is the only image on these documents). */
    public static function imageCount(string $pdf): int
    {
        return substr_count($pdf, '/Subtype /Image');
    }
}
