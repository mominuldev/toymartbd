<?php

declare(strict_types=1);

namespace App\Domain\Fulfilment\Actions;

use App\Domain\Fulfilment\Services\DeliverySettings;
use App\Models\Enums\SettingGroup;
use App\Models\Setting;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Save the store-level free-delivery threshold (`PUT /settings/delivery`). A subtotal at or
 * above it delivers free in every zone; null switches the rule off. The value is the
 * `delivery.free_shipping_threshold` setting {@see DeliverySettings} reads, so the next
 * storefront quote uses it.
 */
final readonly class UpdateFreeDeliveryThreshold
{
    public function __construct(private DeliverySettings $settings) {}

    public function handle(?Money $threshold, ?int $userId): void
    {
        DB::transaction(static function () use ($threshold, $userId): void {
            Setting::put(DeliverySettings::PREFIX.'free_shipping_threshold', $threshold?->toDecimal(), SettingGroup::Delivery, updatedBy: $userId);
        });
        $this->settings->refresh();
    }
}
