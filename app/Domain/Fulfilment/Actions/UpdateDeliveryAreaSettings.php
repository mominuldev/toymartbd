<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\DTOs\DeliveryAreaSettings;
use App\Domain\Fulfilment\Events\DeliveryReferenceDataChanged;
use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Save Store settings › Delivery areas & timing (`PUT /settings/delivery-areas`): cut-off,
 * ETA days, metro/suburb districts, not-serviced upazilas and the COD advance rule. Writes
 * only the `delivery.*` keys whose value changed, in one transaction, and — only when
 * something changed — dispatches {@see DeliveryReferenceDataChanged} (`delivery_areas`),
 * held until commit, so the storefront `delivery` tag is purged by a queued job (rule 4).
 */
final readonly class UpdateDeliveryAreaSettings
{
    public function __construct(private DeliverySettings $settings) {}

    /** @return list<string> the keys (without prefix) that changed; empty = nothing written */
    public function handle(DeliveryAreaSettings $data, ?int $userId): array
    {
        $changed = DB::transaction(function () use ($data, $userId): array {
            $this->settings->refresh();
            $current = DeliveryAreaSettings::fromSettings($this->settings)->toSettings();
            $changed = [];

            foreach ($data->toSettings() as $key => $value) {
                if ($current[$key] === $value) {
                    continue;
                }
                Setting::put(DeliverySettings::PREFIX.$key, $value, SettingGroup::Delivery, updatedBy: $userId);
                $changed[] = $key;
            }

            if ($changed !== []) {
                // The rate table (ETA, cut-off) and the district/upazila selectors (zone,
                // serviceability) are cached under `delivery`; purged after commit.
                DeliveryReferenceDataChanged::dispatch('delivery_areas');
            }

            return $changed;
        });
        $this->settings->refresh();

        return $changed;
    }
}
