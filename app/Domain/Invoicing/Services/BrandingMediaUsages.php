<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The dark-background logo, store icon and social share image chosen at Store settings › Site &
 * branding, as media usages: the library refuses to delete one while the storefront shows it.
 * (The light logo is the invoice logo row, reported by InvoiceLogoMediaUsages.)
 */
final class BrandingMediaUsages implements MediaUsageProvider
{
    /** Settings key → [title EN, title BN]. */
    private const array SLOTS = [
        StoreBranding::PREFIX.'logo_dark_media_id' => ['Dark-background logo', 'গাঢ় ব্যাকগ্রাউন্ডের লোগো'],
        StoreBranding::PREFIX.'icon_media_id' => ['Store icon & favicon', 'স্টোর আইকন ও ফেভিকন'],
        StoreBranding::PREFIX.'share_image_media_id' => ['Social share image', 'সোশ্যাল শেয়ার ছবি'],
    ];

    public function referencing(): Builder
    {
        // `settings.value` is JSON holding the bare integer id (see InvoiceLogoMediaUsages).
        return DB::table('settings')->select(DB::raw(1))
            ->whereIn('settings.key', array_keys(self::SLOTS))
            ->whereColumn('settings.value', 'media.id');
    }

    public function subjectMatching(string $like): ?Builder
    {
        return null;
    }

    public function usagesFor(array $mediaIds): array
    {
        $out = [];
        foreach (DB::table('settings')->whereIn('key', array_keys(self::SLOTS))->pluck('value', 'key') as $key => $raw) {
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $id = StoreBranding::id($decoded);
            if ($id === null || ! in_array($id, $mediaIds, true)) {
                continue;
            }
            [$title, $titleBn] = self::SLOTS[(string) $key];
            $out[$id][] = new MediaUsage(
                type: 'store_branding',
                subjectType: 'settings',
                subjectId: 0,
                title: $title,
                titleBn: $titleBn,
                adminPath: '/admin/settings/branding',
            );
        }

        return $out;
    }
}
