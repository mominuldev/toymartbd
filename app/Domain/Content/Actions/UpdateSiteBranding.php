<?php

declare(strict_types=1);

namespace App\Domain\Content\Actions;

use App\Domain\Content\DTOs\SiteBrandingData;
use App\Domain\Content\Services\SiteBranding;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Saves Store settings › Site & branding into `settings` (group `store`, keys `store.*`): the
 * whole section in one transaction, a null clearing its key. `updated_by` records who saved each
 * value, as the other settings sections do. Returns whether anything changed, so the caller
 * purges the storefront's `store-info` tag only when it must (queued, after commit — rule 4).
 */
final readonly class UpdateSiteBranding
{
    public function __construct(private SiteBranding $branding) {}

    public function handle(SiteBrandingData $data, ?int $userId): bool
    {
        return DB::transaction(function () use ($data, $userId): bool {
            $before = $this->branding->saved();
            $changed = false;

            foreach (array_keys(SiteBranding::TEXT_FIELDS) as $field) {
                $changed = self::write($field, $before[$field] ?? null, $data->text[$field] ?? null, $userId) || $changed;
            }
            foreach (SiteBranding::MEDIA_FIELDS as $field) {
                $changed = self::write($field, $before[$field] ?? null, $data->media[$field] ?? null, $userId) || $changed;
            }

            return $changed;
        });
    }

    private static function write(string $field, string|int|null $old, string|int|null $value, ?int $userId): bool
    {
        if ($old === $value) {
            return false;
        }
        $key = SiteBranding::key($field);
        if ($value === null) {
            Setting::query()->whereKey($key)->delete();
        } else {
            Setting::put($key, $value, SettingGroup::Store, false, $userId);
        }

        return true;
    }
}
