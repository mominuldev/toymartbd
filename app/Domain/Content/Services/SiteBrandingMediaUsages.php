<?php

declare(strict_types=1);

namespace App\Domain\Content\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The Site & branding images (`settings` keys `store.logo_media_id`, `store.logo_dark_media_id`,
 * `store.icon_media_id`, `store.social_image_media_id`) as media usages: the library refuses to
 * delete one while the storefront shows it, so the header, the favicon and shared links never
 * point at a deleted file.
 */
final class SiteBrandingMediaUsages implements MediaUsageProvider
{
    /** Field → [English title, Bangla title]. */
    private const array TITLES = [
        'logo_media_id' => ['Store logo', 'স্টোরের লোগো'],
        'logo_dark_media_id' => ['Store logo (dark backgrounds)', 'স্টোরের লোগো (গাঢ় পটভূমি)'],
        'icon_media_id' => ['Store icon & favicon', 'স্টোর আইকন ও ফেভিকন'],
        'social_image_media_id' => ['Social share image', 'শেয়ার করার ছবি'],
    ];

    public function referencing(): Builder
    {
        // `settings.value` is JSON holding the bare integer id; both MySQL (JSON vs int) and
        // SQLite (TEXT affinity coerced to the numeric side) compare it to `media.id`.
        return DB::table('settings')->select(DB::raw(1))
            ->whereIn('settings.key', self::keys())
            ->whereColumn('settings.value', 'media.id');
    }

    public function subjectMatching(string $like): ?Builder
    {
        return null;
    }

    public function usagesFor(array $mediaIds): array
    {
        $rows = DB::table('settings')->whereIn('key', self::keys())->get(['key', 'value']);

        $out = [];
        foreach (SiteBranding::MEDIA_FIELDS as $field) {
            $row = $rows->firstWhere('key', SiteBranding::key($field));
            $raw = $row?->value;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $id = is_int($decoded) || (is_string($decoded) && ctype_digit($decoded)) ? (int) $decoded : null;
            if ($id === null || ! in_array($id, $mediaIds, true)) {
                continue;
            }
            [$title, $titleBn] = self::TITLES[$field];
            $out[$id][] = new MediaUsage(
                type: 'site_branding',
                subjectType: 'settings',
                subjectId: 0,
                title: $title,
                titleBn: $titleBn,
                adminPath: '/admin/settings/site',
            );
        }

        return $out;
    }

    /** @return list<string> */
    private static function keys(): array
    {
        return array_map(SiteBranding::key(...), SiteBranding::MEDIA_FIELDS);
    }
}
