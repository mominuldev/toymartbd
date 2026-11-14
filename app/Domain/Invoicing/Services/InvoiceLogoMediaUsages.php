<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Services;

use App\Domain\Catalog\Media\Contracts\MediaUsageProvider;
use App\Domain\Catalog\Media\DTOs\MediaUsage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The invoice logo chosen at Settings → Invoice details (`settings` key
 * `invoice.company.logo_media_id`) as a media usage (P9 batch 1): the library refuses to
 * delete it while it is the logo, so the next invoice never references a deleted file.
 *
 * Documents already issued reference their logo's rendition file, not this setting. A
 * former logo deleted from the library afterwards drops off their reprints (no image, never
 * a broken box) — checking every issued snapshot here would mean a JSON scan of `invoices`
 * and `credit_notes` on every media-list request.
 */
final class InvoiceLogoMediaUsages implements MediaUsageProvider
{
    private const string KEY = CompanyDetails::PREFIX.'logo_media_id';

    public function referencing(): Builder
    {
        // `settings.value` is JSON holding the bare integer id; both MySQL (JSON vs int) and
        // SQLite (TEXT affinity coerced to the numeric side) compare it to `media.id`.
        return DB::table('settings')->select(DB::raw(1))
            ->where('settings.key', self::KEY)
            ->whereColumn('settings.value', 'media.id');
    }

    public function subjectMatching(string $like): ?Builder
    {
        return null;
    }

    public function usagesFor(array $mediaIds): array
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $id = is_int($decoded) || (is_string($decoded) && ctype_digit($decoded)) ? (int) $decoded : null;
        if ($id === null || ! in_array($id, $mediaIds, true)) {
            return [];
        }

        return [$id => [new MediaUsage(
            type: 'invoice_logo',
            subjectType: 'settings',
            subjectId: 0,
            title: 'Invoice logo',
            titleBn: 'ইনভয়েসের লোগো',
            adminPath: '/admin/settings/invoice',
        )]];
    }
}
