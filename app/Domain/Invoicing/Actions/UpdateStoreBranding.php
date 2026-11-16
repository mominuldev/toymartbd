<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\DTOs\StoreBrandingData;
use App\Domain\Invoicing\Services\StoreBranding;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Store settings › Site & branding — the whole section, `updated_by` on every row like the
 * other store-settings saves. The logo is written to `invoice.company.logo_media_id`, the very
 * row Invoice details edits (one logo: storefront header, invoices, emails); invoices already
 * issued keep the logo file in their snapshot.
 *
 * Database writes only. Returns whether the store icon changed, so the caller can queue the
 * favicon render after commit; a changed icon drops the favicons rendered from the old one.
 */
final readonly class UpdateStoreBranding
{
    public function handle(StoreBrandingData $data, ?int $userId): bool
    {
        return DB::transaction(function () use ($data, $userId): bool {
            $iconKey = StoreBranding::MEDIA_KEYS['icon_media_id'];
            $before = StoreBranding::id(Setting::query()->whereKey($iconKey)->lockForUpdate()->first()?->value);

            foreach (array_keys(StoreBranding::TEXT_FIELDS) as $field) {
                $value = $data->texts[$field] ?? null;
                self::write(StoreBranding::PREFIX.$field, $value === null || trim($value) === '' ? null : trim($value), $userId);
            }
            foreach (StoreBranding::MEDIA_KEYS as $field => $key) {
                self::write($key, $data->media[$field] ?? null, $userId);
            }

            $after = $data->media['icon_media_id'] ?? null;
            if ($before === $after) {
                return false;
            }
            Setting::query()->whereKey(StoreBranding::ICON_PNGS_KEY)->delete();

            return true;
        });
    }

    private static function write(string $key, string|int|null $value, ?int $userId): void
    {
        if ($value === null) {
            Setting::query()->whereKey($key)->delete();

            return;
        }
        Setting::put($key, $value, SettingGroup::Store, false, $userId);
    }
}
